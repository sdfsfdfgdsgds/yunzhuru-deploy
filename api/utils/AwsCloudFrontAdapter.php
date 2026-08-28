<?php

/**
 * AWS CloudFront 执行适配器。
 *
 * 责任：
 * 1. 按 credential_ref 从 Railway Variables 读取 AWS 身份，业务表只保存引用名。
 * 2. 用纯 PHP 实现 AWS Signature Version 4（SigV4），通过 curl 调用 STS 与 CloudFront。
 * 3. 创建固定安全模板的 CloudFront 默认域名，查询状态，禁用并删除资源。
 * 4. 为调度器提供 CallerReference 幂等恢复入口，避免“AWS 已创建、DB 未落账”时重复创建。
 *
 * 本文件没有 Composer 依赖，XML 使用内建的受限解析器，不依赖 SimpleXML/DOM。
 * 生产传输只允许固定 AWS HTTPS 端点，禁止重定向并强制 TLS 证书校验。
 */

if (!class_exists('AwsCloudFrontAdapterException')) {
    /** 只携带结构化安全字段，异常文本不包含 AWS 响应原文或认证材料。 */
    final class AwsCloudFrontAdapterException extends RuntimeException
    {
        /** @var string */
        private $reasonCode;
        /** @var int */
        private $httpStatus;
        /** @var string */
        private $awsRequestId;

        public function __construct(
            string $reasonCode,
            int $httpStatus = 0,
            string $awsRequestId = '',
            ?Throwable $previous = null
        ) {
            $this->reasonCode = $reasonCode;
            $this->httpStatus = $httpStatus;
            $this->awsRequestId = $awsRequestId;
            $message = 'AWS 执行失败（reason_code=' . $reasonCode
                . ', http_status=' . $httpStatus
                . ', request_id=' . ($awsRequestId !== '' ? $awsRequestId : '-') . '）';
            parent::__construct($message, 0, $previous);
        }

        public function getReasonCode(): string
        {
            return $this->reasonCode;
        }

        public function getHttpStatus(): int
        {
            return $this->httpStatus;
        }

        public function getAwsRequestId(): string
        {
            return $this->awsRequestId;
        }
    }
}

if (!class_exists('AwsCloudFrontAdapter')) {
    final class AwsCloudFrontAdapter
    {
        public const API_VERSION = '2020-05-31';
        /** AWS 托管策略 Managed-CachingDisabled，配置 API 响应不进入 CloudFront 缓存。 */
        public const CACHE_POLICY_DISABLED = '4135ea2d-6df8-44a3-9df3-4b5a84be39ad';
        public const ORIGIN_REQUEST_POLICY_ALL_EXCEPT_HOST = 'b689b0a8-53d0-40ab-baf2-68738e2966ac';
        /** 单次 STS+CloudFront 写链路的最坏等待低于 Supervisor 40 秒停机宽限。 */
        public const CONNECT_TIMEOUT_SECONDS = 3;
        public const REQUEST_TIMEOUT_SECONDS = 15;
        public const MAX_RESPONSE_BYTES = 2097152;
        public const MAX_LIST_PAGES = 5;
        public const MAX_RECOVERY_CANDIDATES = 20;
        public const MAX_RESOURCE_TOKEN_LENGTH = 48;

        /** @var string */
        private $credentialRef;
        /** @var string */
        private $region;
        /** @var string */
        private $authType;
        /** @var string */
        private $roleArnOverride;
        /** @var string */
        private $externalIdRef;
        /** @var callable|null */
        private $transport;
        /** @var callable */
        private $environmentReader;
        /** @var callable */
        private $clock;
        /** @var array|null */
        private $effectiveCredentials;

        /**
         * 工厂入口。$options 只用于区域及可测试依赖注入，生产不接收可变 AWS 端点。
         *
         * @param array{region?:string,auth_type?:string,role_arn?:string,external_id_ref?:string,transport?:callable,env_reader?:callable,clock?:callable} $options
         */
        public static function fromCredentialReference(string $credentialRef, array $options = []): self
        {
            return new self($credentialRef, $options);
        }

        /**
         * credential_ref 到 Railway Variables 的唯一映射合同。
         *
         * 例如 PRIMARY 映射到 AWS_CDN_PRIMARY_ACCESS_KEY_ID 等变量。
         * 返回值只有变量名，不返回变量内容。
         *
         * @return array<string,string>
         */
        public static function credentialEnvironmentNames(string $credentialRef): array
        {
            self::assertCredentialReference($credentialRef);
            $prefix = 'AWS_CDN_' . $credentialRef . '_';
            return [
                'access_key_id' => $prefix . 'ACCESS_KEY_ID',
                'secret_access_key' => $prefix . 'SECRET_ACCESS_KEY',
                'session_token' => $prefix . 'SESSION_TOKEN',
                'role_arn' => $prefix . 'ROLE_ARN',
                'role_session_name' => $prefix . 'ROLE_SESSION_NAME',
                'external_id' => $prefix . 'EXTERNAL_ID',
            ];
        }

        /**
         * 构建可直接用于 CreateDistribution/UpdateDistribution 的固定模板。
         *
         * 必填：caller_reference、origin_domain。
         * 可选：resource_token、public_path、origin_path、comment、price_class、ipv6_enabled。
         */
        public static function buildDistributionConfigXml(array $spec): string
        {
            $normalized = self::normalizeDistributionSpec($spec);
            return '<?xml version="1.0" encoding="UTF-8"?>'
                . self::buildDistributionConfigElement($normalized, true);
        }

        /**
         * 核对当前有效 AWS 身份；如配置 ROLE_ARN，先 AssumeRole 再核对。
         *
         * @return array{credential_ref:string,account_id:string,arn:string,user_id:string,assumed_role:bool}
         */
        public function verifyIdentity(?string $expectedAccountId = null): array
        {
            if ($expectedAccountId !== null && preg_match('/^\d{12}$/', $expectedAccountId) !== 1) {
                throw new InvalidArgumentException('期望 AWS Account ID 必须是 12 位数字');
            }
            $credentials = $this->effectiveCredentials();
            $response = $this->requestSts('GetCallerIdentity', [], $credentials);
            $resultInner = self::xmlElementInner($response['body'], 'GetCallerIdentityResult');
            $accountId = self::xmlDirectChildValue($resultInner, 'Account');
            $arn = self::xmlDirectChildValue($resultInner, 'Arn');
            $userId = self::xmlDirectChildValue($resultInner, 'UserId');
            if (preg_match('/^\d{12}$/', $accountId) !== 1 || $arn === '' || $userId === '') {
                throw new AwsCloudFrontAdapterException('invalid_sts_response', $response['status'], $response['request_id']);
            }
            if ($expectedAccountId !== null && !hash_equals($expectedAccountId, $accountId)) {
                throw new AwsCloudFrontAdapterException('account_id_mismatch', $response['status'], $response['request_id']);
            }
            return [
                'credential_ref' => $this->credentialRef,
                'account_id' => $accountId,
                'arn' => $arn,
                'user_id' => $userId,
                'assumed_role' => !empty($credentials['assumed_role']),
            ];
        }

        /**
         * 验证 STS 身份与 CloudFront 控制面只读权限。
         *
         * 连接门禁只调用 GetCallerIdentity 和 ListDistributions(MaxItems=1)，不创建、修改或删除资源。
         * Create/Update/Delete 写权限由对应首次动作的 AWS 回执确认。
         *
         * @return array{credential_ref:string,account_id:string,arn:string,user_id:string,assumed_role:bool,read_access:bool}
         */
        public function verifyControlPlane(?string $expectedAccountId = null): array
        {
            $identity = $this->verifyIdentity($expectedAccountId);
            $response = $this->requestCloudFront('GET', '/distribution', ['MaxItems' => '1'], '', [], [200]);
            self::xmlElementInner($response['body'], 'DistributionList');
            $identity['read_access'] = true;
            return $identity;
        }

        /**
         * 用 CreateDistributionWithTags 创建默认 cloudfront.net 分配。
         *
         * 标签与 Comment 都写入 resource_token，后续只对账本匹配的资源执行清理。
         *
         * @return array<string,mixed>
         */
        public function createDistribution(array $spec): array
        {
            $normalized = self::normalizeDistributionSpec($spec);
            $config = self::buildDistributionConfigElement($normalized, false);
            $body = '<?xml version="1.0" encoding="UTF-8"?>'
                . '<DistributionConfigWithTags xmlns="http://cloudfront.amazonaws.com/doc/' . self::API_VERSION . '/">'
                . $config
                . '<Tags><Items>'
                . '<Tag><Key>yunzhuru:managed-by</Key><Value>api-domain-automation</Value></Tag>'
                . '<Tag><Key>yunzhuru:resource-token</Key><Value>' . self::xmlEscape($normalized['resource_token']) . '</Value></Tag>'
                . '</Items></Tags>'
                . '</DistributionConfigWithTags>';

            $response = $this->requestCloudFront(
                'POST',
                '/distribution',
                ['WithTags' => ''],
                $body,
                ['content-type' => 'application/xml'],
                [201]
            );
            $result = $this->parseDistributionResponse($response);
            $result['public_api_url'] = 'https://' . $result['domain_name'] . $normalized['public_path'];
            $result['resource_token'] = $normalized['resource_token'];
            $result['caller_reference'] = $normalized['caller_reference'];
            return $result;
        }

        /** @return array<string,mixed> */
        public function getDistribution(string $distributionId): array
        {
            $distributionId = self::normalizeDistributionId($distributionId);
            $response = $this->requestCloudFront(
                'GET',
                '/distribution/' . rawurlencode($distributionId),
                [],
                '',
                [],
                [200]
            );
            return $this->parseDistributionResponse($response);
        }

        /**
         * 取回完整配置及 ETag，config_xml 只包含 CloudFront 配置，不包含 AWS 认证材料。
         *
         * @return array<string,mixed>
         */
        public function getDistributionConfig(string $distributionId): array
        {
            $distributionId = self::normalizeDistributionId($distributionId);
            $response = $this->requestCloudFront(
                'GET',
                '/distribution/' . rawurlencode($distributionId) . '/config',
                [],
                '',
                [],
                [200]
            );
            $etag = self::normalizeEtag($response['headers']['etag'] ?? '');
            if ($etag === '') {
                throw new AwsCloudFrontAdapterException('missing_etag', $response['status'], $response['request_id']);
            }
            $configInner = self::xmlElementInner($response['body'], 'DistributionConfig');
            $callerReference = self::xmlDirectChildValue($configInner, 'CallerReference');
            $enabledText = strtolower(self::xmlDirectChildValue($configInner, 'Enabled'));
            if ($callerReference === '' || !in_array($enabledText, ['true', 'false'], true)) {
                throw new AwsCloudFrontAdapterException('invalid_cloudfront_response', $response['status'], $response['request_id']);
            }
            return [
                'distribution_id' => $distributionId,
                'etag' => $etag,
                'enabled' => $enabledText === 'true',
                'caller_reference' => $callerReference,
                'comment' => self::xmlDirectChildValue($configInner, 'Comment'),
                'config_xml' => $response['body'],
                'http_status' => $response['status'],
                'request_id' => $response['request_id'],
            ];
        }

        /**
         * 幂等禁用分配。已禁用时不再发送 UpdateDistribution。
         *
         * @return array<string,mixed>
         */
        public function disableDistribution(string $distributionId): array
        {
            return $this->setDistributionEnabled($distributionId, false);
        }

        /**
         * 幂等重新启用分配。清理宽限期发现真实访问时，调度器用此入口撤销停用。
         *
         * @return array<string,mixed>
         */
        public function enableDistribution(string $distributionId): array
        {
            return $this->setDistributionEnabled($distributionId, true);
        }

        /**
         * 在只读取一次配置的前提下禁用自有分配。
         *
         * 调度器应传入业务账本中的 CallerReference 和 resource_token。适配器会在同一份
         * GetDistributionConfig 回应中严格验证这两个标识，通过后直接使用该份配置的 ETag 执行 PUT。
         * 因此比“外层取配置 + 适配器再取配置”少一次网络读取。
         *
         * @return array<string,mixed>
         */
        public function disableOwnedDistribution(
            string $distributionId,
            string $expectedCallerReference,
            string $expectedResourceToken
        ): array {
            return $this->setOwnedDistributionEnabled(
                $distributionId,
                $expectedCallerReference,
                $expectedResourceToken,
                false
            );
        }

        /**
         * 在只读取一次配置的前提下重新启用自有分配。
         * 用于清理宽限期内检测到真实访问后撤销停用。
         *
         * @return array<string,mixed>
         */
        public function enableOwnedDistribution(
            string $distributionId,
            string $expectedCallerReference,
            string $expectedResourceToken
        ): array {
            return $this->setOwnedDistributionEnabled(
                $distributionId,
                $expectedCallerReference,
                $expectedResourceToken,
                true
            );
        }

        /**
         * 在只读取一次配置的前提下删除自有且已禁用的分配。
         * 如传入 expectedEtag，仍会与本次读取的最新 ETag 比对；删除请求始终使用最新值。
         *
         * @return array{distribution_id:string,deleted:bool,http_status:int,request_id:string,ownership_verified:bool,caller_reference:string,resource_token:string}
         */
        public function deleteOwnedDistribution(
            string $distributionId,
            string $expectedCallerReference,
            string $expectedResourceToken,
            ?string $expectedEtag = null
        ): array {
            $distributionId = self::normalizeDistributionId($distributionId);
            $config = $this->getDistributionConfig($distributionId);
            $ownership = $this->assertOwnedDistributionConfig(
                $config,
                $expectedCallerReference,
                $expectedResourceToken
            );
            if (!empty($config['enabled'])) {
                throw new AwsCloudFrontAdapterException(
                    'distribution_still_enabled',
                    (int)($config['http_status'] ?? 0),
                    (string)($config['request_id'] ?? '')
                );
            }
            if ($expectedEtag !== null) {
                $providedEtag = self::normalizeEtag($expectedEtag);
                if ($providedEtag === '' || !hash_equals((string)$config['etag'], $providedEtag)) {
                    throw new AwsCloudFrontAdapterException(
                        'stale_etag',
                        (int)($config['http_status'] ?? 0),
                        (string)($config['request_id'] ?? '')
                    );
                }
            }
            $response = $this->requestCloudFront(
                'DELETE',
                '/distribution/' . rawurlencode($distributionId),
                [],
                '',
                ['if-match' => (string)$config['etag']],
                [204]
            );
            return [
                'distribution_id' => $distributionId,
                'deleted' => true,
                'http_status' => $response['status'],
                'request_id' => $response['request_id'],
                'ownership_verified' => true,
                'caller_reference' => $ownership['caller_reference'],
                'resource_token' => $ownership['resource_token'],
            ];
        }

        /** @return array<string,mixed> */
        private function setDistributionEnabled(string $distributionId, bool $targetEnabled): array
        {
            $distributionId = self::normalizeDistributionId($distributionId);
            $config = $this->getDistributionConfig($distributionId);
            if ((bool)$config['enabled'] === $targetEnabled) {
                return [
                    'distribution_id' => $distributionId,
                    'status' => 'unchanged',
                    'enabled' => $targetEnabled,
                    'etag' => $config['etag'],
                    'request_id' => $config['request_id'],
                    'changed' => false,
                ];
            }
            $updatedXml = self::replaceDirectXmlChildText(
                $config['config_xml'],
                'DistributionConfig',
                'Enabled',
                $targetEnabled ? 'true' : 'false'
            );
            $response = $this->requestCloudFront(
                'PUT',
                '/distribution/' . rawurlencode($distributionId) . '/config',
                [],
                $updatedXml,
                ['content-type' => 'application/xml', 'if-match' => $config['etag']],
                [200]
            );
            $result = $this->parseDistributionResponse($response);
            $result['changed'] = true;
            return $result;
        }

        /** @return array<string,mixed> */
        private function setOwnedDistributionEnabled(
            string $distributionId,
            string $expectedCallerReference,
            string $expectedResourceToken,
            bool $targetEnabled
        ): array {
            $distributionId = self::normalizeDistributionId($distributionId);
            // 这一份配置同时承担所有权校验、目标状态判断和 If-Match 来源，整个写入口只读取一次。
            $config = $this->getDistributionConfig($distributionId);
            $ownership = $this->assertOwnedDistributionConfig(
                $config,
                $expectedCallerReference,
                $expectedResourceToken
            );
            if ((bool)$config['enabled'] === $targetEnabled) {
                return [
                    'distribution_id' => $distributionId,
                    'status' => 'unchanged',
                    'enabled' => $targetEnabled,
                    'etag' => $config['etag'],
                    'request_id' => $config['request_id'],
                    'changed' => false,
                    'ownership_verified' => true,
                    'caller_reference' => $ownership['caller_reference'],
                    'resource_token' => $ownership['resource_token'],
                ];
            }
            $updatedXml = self::replaceDirectXmlChildText(
                $config['config_xml'],
                'DistributionConfig',
                'Enabled',
                $targetEnabled ? 'true' : 'false'
            );
            $response = $this->requestCloudFront(
                'PUT',
                '/distribution/' . rawurlencode($distributionId) . '/config',
                [],
                $updatedXml,
                ['content-type' => 'application/xml', 'if-match' => $config['etag']],
                [200]
            );
            $result = $this->parseDistributionResponse($response);
            $result['changed'] = true;
            $result['ownership_verified'] = true;
            $result['caller_reference'] = $ownership['caller_reference'];
            $result['resource_token'] = $ownership['resource_token'];
            return $result;
        }

        /**
         * 在适配器内部完成账本所有权校验。标识不匹配时只返回固定 reason_code，不暴露对方配置内容。
         *
         * @param array<string,mixed> $config getDistributionConfig() 返回值
         * @return array{caller_reference:string,resource_token:string}
         */
        private function assertOwnedDistributionConfig(
            array $config,
            string $expectedCallerReference,
            string $expectedResourceToken
        ): array {
            $callerReference = self::normalizeCallerReference($expectedCallerReference);
            $resourceToken = self::normalizeResourceToken($expectedResourceToken);
            $actualCallerReference = (string)($config['caller_reference'] ?? '');
            $comment = (string)($config['comment'] ?? '');
            $callerHash = self::resourceTokenForCallerReference($callerReference);
            $owned = $actualCallerReference !== ''
                && hash_equals($callerReference, $actualCallerReference)
                && preg_match('/(?:^|\|)yunzhuru-api-domain(?:\||$)/', $comment) === 1
                && preg_match('/(?:^|\|)caller_hash=' . preg_quote($callerHash, '/') . '(?:\||$)/', $comment) === 1
                && preg_match('/(?:^|\|)resource_token=' . preg_quote($resourceToken, '/') . '(?:\||$)/', $comment) === 1;
            if (!$owned) {
                throw new AwsCloudFrontAdapterException(
                    'ownership_mismatch',
                    (int)($config['http_status'] ?? 0),
                    (string)($config['request_id'] ?? '')
                );
            }
            return [
                'caller_reference' => $callerReference,
                'resource_token' => $resourceToken,
            ];
        }

        /**
         * 删除前强制重新取得最新配置，只允许删除已禁用的分配。
         *
         * @return array{distribution_id:string,deleted:bool,http_status:int,request_id:string}
         */
        public function deleteDistribution(string $distributionId, ?string $etag = null): array
        {
            $distributionId = self::normalizeDistributionId($distributionId);
            $latest = $this->getDistributionConfig($distributionId);
            if ($latest['enabled']) {
                throw new AwsCloudFrontAdapterException('distribution_still_enabled');
            }
            if ($etag !== null) {
                $providedEtag = self::normalizeEtag($etag);
                if ($providedEtag === '' || !hash_equals($latest['etag'], $providedEtag)) {
                    throw new AwsCloudFrontAdapterException('stale_etag');
                }
            }
            $response = $this->requestCloudFront(
                'DELETE',
                '/distribution/' . rawurlencode($distributionId),
                [],
                '',
                ['if-match' => $latest['etag']],
                [204]
            );
            return [
                'distribution_id' => $distributionId,
                'deleted' => true,
                'http_status' => $response['status'],
                'request_id' => $response['request_id'],
            ];
        }

        /**
         * 从 ListDistributions 分页结果中用 Comment 资源标记缩小范围，
         * 再逐个 GetDistributionConfig 精确比对 CallerReference。
         *
         * @return array<string,mixed>|null
         */
        public function findDistributionByCallerReference(string $callerReference): ?array
        {
            $callerReference = self::normalizeCallerReference($callerReference);
            $callerHash = self::resourceTokenForCallerReference($callerReference);
            // resource_token 可由业务账本指定；CallerReference 哈希始终固定，用于碰撞恢复时缩小候选集。
            $commentMarker = 'caller_hash=' . $callerHash;
            $marker = '';
            $checkedCandidates = 0;

            for ($page = 0; $page < self::MAX_LIST_PAGES; $page++) {
                $query = ['MaxItems' => '100'];
                if ($marker !== '') $query['Marker'] = $marker;
                $response = $this->requestCloudFront('GET', '/distribution', $query, '', [], [200]);
                $listInner = self::xmlElementInner($response['body'], 'DistributionList');
                $itemsInner = self::xmlDirectChildInner($listInner, 'Items');
                if ($itemsInner !== null) {
                    foreach (self::xmlElementInners($itemsInner, 'DistributionSummary') as $summaryInner) {
                        $comment = self::xmlDirectChildValue($summaryInner, 'Comment');
                        if ($comment === '' || strpos($comment, $commentMarker) === false) continue;
                        $checkedCandidates++;
                        if ($checkedCandidates > self::MAX_RECOVERY_CANDIDATES) {
                            throw new AwsCloudFrontAdapterException('recovery_scan_limit');
                        }
                        $distributionId = self::xmlDirectChildValue($summaryInner, 'Id');
                        if ($distributionId === '') continue;
                        $config = $this->getDistributionConfig($distributionId);
                        if (!hash_equals($callerReference, $config['caller_reference'])) continue;
                        $summary = self::parseDistributionInner($summaryInner, [
                            'status' => $response['status'],
                            'headers' => [],
                            'request_id' => $response['request_id'],
                        ]);
                        $summary['etag'] = $config['etag'];
                        $summary['caller_reference'] = $callerReference;
                        $summary['resource_token'] = self::resourceTokenFromComment($comment, $callerHash);
                        $summary['public_api_url'] = 'https://' . $summary['domain_name'] . '/shell.php';
                        return $summary;
                    }
                }
                $truncated = strtolower(self::xmlDirectChildValue($listInner, 'IsTruncated')) === 'true';
                if (!$truncated) return null;
                $marker = self::xmlDirectChildValue($listInner, 'NextMarker');
                if ($marker === '') {
                    throw new AwsCloudFrontAdapterException('invalid_cloudfront_response', $response['status'], $response['request_id']);
                }
            }
            throw new AwsCloudFrontAdapterException('recovery_scan_limit');
        }

        /** @param array<string,mixed> $options */
        private function __construct(string $credentialRef, array $options)
        {
            self::assertCredentialReference($credentialRef);
            $this->credentialRef = $credentialRef;
            $this->region = isset($options['region']) ? trim((string)$options['region']) : 'us-east-1';
            if (preg_match('/^[a-z]{2}(?:-gov)?-[a-z]+-\d$/', $this->region) !== 1) {
                throw new InvalidArgumentException('AWS 区域格式不正确');
            }
            $this->authType = isset($options['auth_type']) ? trim((string)$options['auth_type']) : 'environment';
            if (!in_array($this->authType, ['environment', 'assume_role'], true)) {
                throw new InvalidArgumentException('auth_type 只允许 environment 或 assume_role');
            }
            $this->roleArnOverride = isset($options['role_arn']) ? trim((string)$options['role_arn']) : '';
            $this->externalIdRef = isset($options['external_id_ref']) ? trim((string)$options['external_id_ref']) : '';
            if ($this->externalIdRef !== '') self::assertCredentialReference($this->externalIdRef);
            $this->transport = isset($options['transport']) ? $options['transport'] : null;
            if ($this->transport !== null && !is_callable($this->transport)) {
                throw new InvalidArgumentException('transport 必须可调用');
            }
            $this->environmentReader = isset($options['env_reader']) ? $options['env_reader'] : static function (string $name) {
                $value = getenv($name);
                return $value === false ? '' : $value;
            };
            if (!is_callable($this->environmentReader)) {
                throw new InvalidArgumentException('env_reader 必须可调用');
            }
            $this->clock = isset($options['clock']) ? $options['clock'] : static function () {
                return new DateTimeImmutable('now', new DateTimeZone('UTC'));
            };
            if (!is_callable($this->clock)) {
                throw new InvalidArgumentException('clock 必须可调用');
            }
            $this->effectiveCredentials = null;
        }

        /** 防止 var_dump 等调试输出暴露内存中的临时认证材料。 */
        public function __debugInfo(): array
        {
            return [
                'credential_ref' => $this->credentialRef,
                'region' => $this->region,
                'auth_type' => $this->authType,
                'external_id_ref' => $this->externalIdRef,
            ];
        }

        /** 防止序列化适配器时把内存中的认证材料落盘。 */
        public function __serialize(): array
        {
            throw new LogicException('AWS 执行适配器禁止序列化');
        }

        /** @return array<string,mixed> */
        private function effectiveCredentials(): array
        {
            $now = $this->now()->getTimestamp();
            if ($this->effectiveCredentials !== null) {
                $expiresAt = (int)($this->effectiveCredentials['expires_at'] ?? 0);
                if ($expiresAt === 0 || $expiresAt > $now + 300) return $this->effectiveCredentials;
            }

            $names = self::credentialEnvironmentNames($this->credentialRef);
            $base = [
                'access_key_id' => $this->readEnvironment($names['access_key_id']),
                'secret_access_key' => $this->readEnvironment($names['secret_access_key']),
                'session_token' => $this->readEnvironment($names['session_token']),
                'assumed_role' => false,
                'expires_at' => 0,
            ];
            self::validateCredentials($base);

            if ($this->authType === 'environment') {
                $this->effectiveCredentials = $base;
                return $base;
            }
            $roleArn = $this->roleArnOverride !== ''
                ? $this->roleArnOverride
                : trim($this->readEnvironment($names['role_arn']));
            if ($roleArn === '') throw new AwsCloudFrontAdapterException('missing_role_arn');
            if (preg_match('#^arn:(?:aws|aws-us-gov|aws-cn):iam::\d{12}:role/[A-Za-z0-9+=,.@_/-]{1,512}$#', $roleArn) !== 1) {
                throw new AwsCloudFrontAdapterException('invalid_role_arn');
            }
            $sessionName = trim($this->readEnvironment($names['role_session_name']));
            if ($sessionName === '') $sessionName = 'yunzhuru-' . substr(hash('sha256', $this->credentialRef), 0, 12);
            if (preg_match('/^[A-Za-z0-9+=,.@_-]{2,64}$/', $sessionName) !== 1) {
                throw new AwsCloudFrontAdapterException('invalid_role_session_name');
            }
            $params = [
                'RoleArn' => $roleArn,
                'RoleSessionName' => $sessionName,
                'DurationSeconds' => '3600',
            ];
            $externalIdName = $this->externalIdRef !== ''
                ? self::credentialEnvironmentNames($this->externalIdRef)['external_id']
                : $names['external_id'];
            $externalId = $this->readEnvironment($externalIdName);
            if ($externalId !== '') {
                if (strlen($externalId) > 1224 || preg_match('/^[\x20-\x7E]+$/', $externalId) !== 1) {
                    throw new AwsCloudFrontAdapterException('invalid_external_id');
                }
                $params['ExternalId'] = $externalId;
            }

            $response = $this->requestSts('AssumeRole', $params, $base);
            $resultInner = self::xmlElementInner($response['body'], 'AssumeRoleResult');
            $credentialsInner = self::xmlElementInner($resultInner, 'Credentials');
            $expirationText = self::xmlDirectChildValue($credentialsInner, 'Expiration');
            $expiration = strtotime($expirationText);
            $temporary = [
                'access_key_id' => self::xmlDirectChildValue($credentialsInner, 'AccessKeyId'),
                'secret_access_key' => self::xmlDirectChildValue($credentialsInner, 'SecretAccessKey'),
                'session_token' => self::xmlDirectChildValue($credentialsInner, 'SessionToken'),
                'assumed_role' => true,
                'expires_at' => $expiration === false ? 0 : $expiration,
            ];
            self::validateCredentials($temporary);
            if ($temporary['expires_at'] <= $now + 300) {
                throw new AwsCloudFrontAdapterException('invalid_assume_role_expiration', $response['status'], $response['request_id']);
            }
            $this->effectiveCredentials = $temporary;
            return $temporary;
        }

        /** @param array<string,mixed> $credentials */
        private static function validateCredentials(array $credentials): void
        {
            $accessKey = (string)($credentials['access_key_id'] ?? '');
            $secretKey = (string)($credentials['secret_access_key'] ?? '');
            $sessionToken = (string)($credentials['session_token'] ?? '');
            if (preg_match('/^[A-Z0-9]{16,128}$/', $accessKey) !== 1) {
                throw new AwsCloudFrontAdapterException('missing_or_invalid_access_key_id');
            }
            if (strlen($secretKey) < 16 || strlen($secretKey) > 256 || preg_match('/[\r\n\0]/', $secretKey) === 1) {
                throw new AwsCloudFrontAdapterException('missing_or_invalid_secret_access_key');
            }
            if (strlen($sessionToken) > 16384 || preg_match('/[\r\n\0]/', $sessionToken) === 1) {
                throw new AwsCloudFrontAdapterException('invalid_session_token');
            }
            if (!empty($credentials['assumed_role']) && $sessionToken === '') {
                throw new AwsCloudFrontAdapterException('missing_assume_role_session_token');
            }
        }

        private function readEnvironment(string $name): string
        {
            try {
                $value = call_user_func($this->environmentReader, $name);
            } catch (Throwable $failure) {
                throw new AwsCloudFrontAdapterException('environment_read_failed');
            }
            if ($value === false || $value === null) return '';
            if (!is_scalar($value)) throw new AwsCloudFrontAdapterException('environment_value_invalid');
            return (string)$value;
        }

        private function now(): DateTimeImmutable
        {
            try {
                $value = call_user_func($this->clock);
            } catch (Throwable $failure) {
                throw new AwsCloudFrontAdapterException('clock_failed');
            }
            if ($value instanceof DateTimeInterface) {
                return (new DateTimeImmutable('@' . $value->getTimestamp()))->setTimezone(new DateTimeZone('UTC'));
            }
            throw new AwsCloudFrontAdapterException('clock_value_invalid');
        }

        /** @param array<string,string> $credentials @return array<string,mixed> */
        private function requestSts(string $action, array $params, array $credentials): array
        {
            $bodyParams = ['Action' => $action, 'Version' => '2011-06-15'] + $params;
            $body = self::canonicalQuery($bodyParams);
            return $this->sendSignedRequest(
                'POST',
                'https://sts.' . $this->region . '.amazonaws.com',
                '/',
                [],
                $body,
                ['content-type' => 'application/x-www-form-urlencoded; charset=utf-8'],
                'sts',
                $this->region,
                $credentials,
                [200]
            );
        }

        /** @return array<string,mixed> */
        private function requestCloudFront(
            string $method,
            string $relativePath,
            array $query,
            string $body,
            array $headers,
            array $expectedStatuses
        ): array {
            $credentials = $this->effectiveCredentials();
            return $this->sendSignedRequest(
                $method,
                'https://cloudfront.amazonaws.com',
                '/' . self::API_VERSION . $relativePath,
                $query,
                $body,
                $headers,
                'cloudfront',
                'us-east-1',
                $credentials,
                $expectedStatuses
            );
        }

        /**
         * 构造 SigV4 请求并交给可注入传输或生产 curl。
         *
         * 传输异常统一收敛为 reason_code，不把传输层原文带到日志。
         *
         * @param array<string,string> $credentials
         * @return array<string,mixed>
         */
        private function sendSignedRequest(
            string $method,
            string $endpoint,
            string $path,
            array $query,
            string $body,
            array $headers,
            string $service,
            string $region,
            array $credentials,
            array $expectedStatuses
        ): array {
            $request = $this->signRequest($method, $endpoint, $path, $query, $body, $headers, $service, $region, $credentials);
            try {
                $response = $this->transport !== null
                    ? call_user_func($this->transport, $request)
                    : $this->executeCurl($request);
            } catch (AwsCloudFrontAdapterException $failure) {
                throw $failure;
            } catch (Throwable $failure) {
                throw new AwsCloudFrontAdapterException('transport_error');
            }
            $response = self::normalizeTransportResponse($response);
            if (strlen($response['body']) > self::MAX_RESPONSE_BYTES) {
                throw new AwsCloudFrontAdapterException('response_too_large', $response['status']);
            }
            if (trim($response['body']) !== '') self::assertSafeXml($response['body']);
            $requestId = self::responseRequestId($response['headers'], $response['body']);
            $response['request_id'] = $requestId;
            if (!in_array($response['status'], $expectedStatuses, true)) {
                $errorCode = self::awsErrorCode($response['body']);
                throw new AwsCloudFrontAdapterException(
                    self::reasonCodeForAwsError($errorCode, $response['status']),
                    $response['status'],
                    $requestId
                );
            }
            return $response;
        }

        /** @param array<string,string> $credentials @return array<string,mixed> */
        private function signRequest(
            string $method,
            string $endpoint,
            string $path,
            array $query,
            string $body,
            array $headers,
            string $service,
            string $region,
            array $credentials
        ): array {
            $parts = parse_url($endpoint);
            if (!is_array($parts) || ($parts['scheme'] ?? '') !== 'https' || empty($parts['host'])) {
                throw new AwsCloudFrontAdapterException('invalid_fixed_endpoint');
            }
            $host = strtolower((string)$parts['host']);
            $canonicalPath = self::canonicalPath($path);
            $canonicalQuery = self::canonicalQuery($query);
            $now = $this->now();
            $amzDate = $now->format('Ymd\THis\Z');
            $dateStamp = $now->format('Ymd');
            $payloadHash = hash('sha256', $body);

            $signed = [];
            foreach ($headers as $name => $value) {
                $lowerName = strtolower(trim((string)$name));
                if (preg_match('/^[a-z0-9-]+$/', $lowerName) !== 1) {
                    throw new AwsCloudFrontAdapterException('invalid_request_header');
                }
                $signed[$lowerName] = self::normalizeHeaderValue((string)$value);
            }
            $signed['host'] = $host;
            $signed['x-amz-content-sha256'] = $payloadHash;
            $signed['x-amz-date'] = $amzDate;
            if ((string)$credentials['session_token'] !== '') {
                $signed['x-amz-security-token'] = (string)$credentials['session_token'];
            }
            ksort($signed, SORT_STRING);
            $canonicalHeaders = '';
            foreach ($signed as $name => $value) $canonicalHeaders .= $name . ':' . $value . "\n";
            $signedHeaderNames = implode(';', array_keys($signed));
            $canonicalRequest = implode("\n", [
                strtoupper($method),
                $canonicalPath,
                $canonicalQuery,
                $canonicalHeaders,
                $signedHeaderNames,
                $payloadHash,
            ]);
            $scope = $dateStamp . '/' . $region . '/' . $service . '/aws4_request';
            $stringToSign = "AWS4-HMAC-SHA256\n" . $amzDate . "\n" . $scope . "\n" . hash('sha256', $canonicalRequest);
            $kDate = hash_hmac('sha256', $dateStamp, 'AWS4' . $credentials['secret_access_key'], true);
            $kRegion = hash_hmac('sha256', $region, $kDate, true);
            $kService = hash_hmac('sha256', $service, $kRegion, true);
            $kSigning = hash_hmac('sha256', 'aws4_request', $kService, true);
            $signature = hash_hmac('sha256', $stringToSign, $kSigning);
            $signed['authorization'] = 'AWS4-HMAC-SHA256 Credential=' . $credentials['access_key_id'] . '/' . $scope
                . ', SignedHeaders=' . $signedHeaderNames . ', Signature=' . $signature;

            return [
                'method' => strtoupper($method),
                'url' => $endpoint . $canonicalPath . ($canonicalQuery !== '' ? '?' . $canonicalQuery : ''),
                'headers' => $signed,
                'body' => $body,
                'service' => $service,
                'region' => $region,
            ];
        }

        /** @param array<string,mixed> $request @return array<string,mixed> */
        private function executeCurl(array $request): array
        {
            if (!function_exists('curl_init')) {
                throw new AwsCloudFrontAdapterException('curl_extension_missing');
            }
            $handle = curl_init();
            if ($handle === false) throw new AwsCloudFrontAdapterException('curl_init_failed');
            $responseBody = '';
            $responseHeaders = [];
            $tooLarge = false;
            $curlHeaders = [];
            foreach ($request['headers'] as $name => $value) $curlHeaders[] = $name . ': ' . $value;

            $curlOptions = [
                CURLOPT_URL => $request['url'],
                CURLOPT_CUSTOMREQUEST => $request['method'],
                CURLOPT_HTTPHEADER => $curlHeaders,
                CURLOPT_RETURNTRANSFER => false,
                CURLOPT_HEADER => false,
                CURLOPT_FOLLOWLOCATION => false,
                CURLOPT_MAXREDIRS => 0,
                CURLOPT_CONNECTTIMEOUT => self::CONNECT_TIMEOUT_SECONDS,
                CURLOPT_TIMEOUT => self::REQUEST_TIMEOUT_SECONDS,
                CURLOPT_SSL_VERIFYPEER => true,
                CURLOPT_SSL_VERIFYHOST => 2,
                CURLOPT_PROTOCOLS => CURLPROTO_HTTPS,
                CURLOPT_REDIR_PROTOCOLS => CURLPROTO_HTTPS,
                // 认证请求绕过环境 HTTP(S)_PROXY，避免中间层改写 SigV4 签名头或 AWS 端点。
                CURLOPT_PROXY => '',
                CURLOPT_NOPROXY => '*',
                CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
                CURLOPT_NOSIGNAL => true,
                CURLOPT_USERAGENT => 'yunzhuru-cloudfront-adapter/1',
                CURLOPT_HEADERFUNCTION => static function ($handle, string $line) use (&$responseHeaders, &$tooLarge): int {
                    $length = strlen($line);
                    if (preg_match('/^HTTP\//i', $line) === 1) $responseHeaders = [];
                    $position = strpos($line, ':');
                    if ($position !== false) {
                        $name = strtolower(trim(substr($line, 0, $position)));
                        $value = trim(substr($line, $position + 1));
                        if ($name === 'content-length' && ctype_digit($value) && (int)$value > self::MAX_RESPONSE_BYTES) {
                            $tooLarge = true;
                            return 0;
                        }
                        $allowedHeaders = ['etag', 'x-amzn-requestid', 'x-amz-request-id', 'content-length', 'content-type'];
                        if (in_array($name, $allowedHeaders, true) && strlen($value) <= 2048) {
                            // 只保留业务需要的响应头，并去掉控制字符，避免异常代理头污染输出。
                            $responseHeaders[$name] = preg_replace('/[\x00-\x1F\x7F]/', '', $value) ?? '';
                        }
                    }
                    return $length;
                },
                CURLOPT_WRITEFUNCTION => static function ($handle, string $chunk) use (&$responseBody, &$tooLarge): int {
                    if (strlen($responseBody) + strlen($chunk) > self::MAX_RESPONSE_BYTES) {
                        $tooLarge = true;
                        return 0;
                    }
                    $responseBody .= $chunk;
                    return strlen($chunk);
                },
            ];
            // GET/DELETE 不设置 POSTFIELDS，避免 libcurl 附加空请求体或更改请求语义。
            if ($request['body'] !== '' || in_array($request['method'], ['POST', 'PUT', 'PATCH'], true)) {
                $curlOptions[CURLOPT_POSTFIELDS] = $request['body'];
            }
            curl_setopt_array($handle, $curlOptions);
            $success = curl_exec($handle);
            $status = (int)curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
            $curlError = curl_errno($handle);
            curl_close($handle);
            if ($tooLarge) throw new AwsCloudFrontAdapterException('response_too_large', $status);
            if ($success === false || $curlError !== 0) throw new AwsCloudFrontAdapterException('transport_error', $status);
            return ['status' => $status, 'headers' => $responseHeaders, 'body' => $responseBody];
        }

        /** @return array<string,mixed> */
        private static function normalizeTransportResponse($response): array
        {
            if (!is_array($response) || !isset($response['status'])) {
                throw new AwsCloudFrontAdapterException('invalid_transport_response');
            }
            $status = (int)$response['status'];
            $body = isset($response['body']) && is_string($response['body']) ? $response['body'] : '';
            $headers = [];
            $allowedHeaders = ['etag', 'x-amzn-requestid', 'x-amz-request-id', 'content-length', 'content-type'];
            foreach (($response['headers'] ?? []) as $name => $value) {
                if (!is_scalar($value)) continue;
                $normalizedName = strtolower(trim((string)$name));
                $normalizedValue = trim((string)$value);
                if (!in_array($normalizedName, $allowedHeaders, true) || strlen($normalizedValue) > 2048) continue;
                $headers[$normalizedName] = preg_replace('/[\x00-\x1F\x7F]/', '', $normalizedValue) ?? '';
            }
            return ['status' => $status, 'headers' => $headers, 'body' => $body];
        }

        /** @return array<string,mixed> */
        private function parseDistributionResponse(array $response): array
        {
            $inner = self::xmlElementInner($response['body'], 'Distribution');
            return self::parseDistributionInner($inner, $response);
        }

        /** @return array<string,mixed> */
        private static function parseDistributionInner(string $inner, array $response): array
        {
            $configInner = self::xmlDirectChildInner($inner, 'DistributionConfig');
            $enabledText = $configInner !== null
                ? strtolower(self::xmlDirectChildValue($configInner, 'Enabled'))
                : strtolower(self::xmlDirectChildValue($inner, 'Enabled'));
            $id = self::xmlDirectChildValue($inner, 'Id');
            $domain = self::xmlDirectChildValue($inner, 'DomainName');
            if ($id === '' || $domain === '' || !in_array($enabledText, ['true', 'false'], true)) {
                throw new AwsCloudFrontAdapterException(
                    'invalid_cloudfront_response',
                    (int)($response['status'] ?? 0),
                    (string)($response['request_id'] ?? '')
                );
            }
            return [
                'distribution_id' => $id,
                'distribution_arn' => self::xmlDirectChildValue($inner, 'ARN'),
                'domain_name' => $domain,
                'status' => self::xmlDirectChildValue($inner, 'Status'),
                'enabled' => $enabledText === 'true',
                'last_modified_time' => self::xmlDirectChildValue($inner, 'LastModifiedTime'),
                'etag' => self::normalizeEtag(($response['headers']['etag'] ?? self::xmlDirectChildValue($inner, 'ETag'))),
                'request_id' => (string)($response['request_id'] ?? ''),
            ];
        }

        /** @param array<string,mixed> $spec @return array<string,mixed> */
        private static function normalizeDistributionSpec(array $spec): array
        {
            $callerReference = self::normalizeCallerReference((string)($spec['caller_reference'] ?? ''));
            $originDomain = strtolower(trim((string)($spec['origin_domain'] ?? '')));
            self::assertHostname($originDomain);
            $resourceToken = isset($spec['resource_token']) && trim((string)$spec['resource_token']) !== ''
                ? trim((string)$spec['resource_token'])
                : self::resourceTokenForCallerReference($callerReference);
            $resourceToken = self::normalizeResourceToken($resourceToken);
            $publicPath = isset($spec['public_path']) ? trim((string)$spec['public_path']) : '/shell.php';
            if (preg_match('#^/[A-Za-z0-9._~!$&\'()*+,;=:@%/-]{1,512}$#', $publicPath) !== 1
                || strpos($publicPath, '?') !== false || strpos($publicPath, '#') !== false) {
                throw new InvalidArgumentException('public_path 必须是不带查询参数的绝对路径');
            }
            $originPath = isset($spec['origin_path']) ? trim((string)$spec['origin_path']) : '';
            if ($originPath !== '' && (preg_match('#^/[A-Za-z0-9._~!$&\'()*+,;=:@%/-]{0,255}$#', $originPath) !== 1
                || strpos($originPath, '?') !== false || strpos($originPath, '#') !== false)) {
                throw new InvalidArgumentException('origin_path 格式不正确');
            }
            $priceClass = isset($spec['price_class']) ? (string)$spec['price_class'] : 'PriceClass_All';
            if (!in_array($priceClass, ['PriceClass_100', 'PriceClass_200', 'PriceClass_All'], true)) {
                throw new InvalidArgumentException('price_class 格式不正确');
            }
            $ipv6 = array_key_exists('ipv6_enabled', $spec) ? self::normalizeBoolean($spec['ipv6_enabled']) : true;
            $extraComment = isset($spec['comment']) ? trim((string)$spec['comment']) : '';
            if ($extraComment !== '' && preg_match('//u', $extraComment) !== 1) {
                throw new InvalidArgumentException('comment 必须是 UTF-8');
            }
            $callerHash = self::resourceTokenForCallerReference($callerReference);
            $marker = 'yunzhuru-api-domain|caller_hash=' . $callerHash . '|resource_token=' . $resourceToken;
            $comment = $extraComment === '' ? $marker : $marker . '|' . $extraComment;
            $comment = self::limitUtf8($comment, 128);
            return [
                'caller_reference' => $callerReference,
                'origin_domain' => $originDomain,
                'resource_token' => $resourceToken,
                'public_path' => $publicPath,
                'origin_path' => $originPath,
                'price_class' => $priceClass,
                'ipv6_enabled' => $ipv6,
                'comment' => $comment,
            ];
        }

        /** @param array<string,mixed> $spec */
        private static function buildDistributionConfigElement(array $spec, bool $withNamespace): string
        {
            $namespace = $withNamespace ? ' xmlns="http://cloudfront.amazonaws.com/doc/' . self::API_VERSION . '/"' : '';
            return '<DistributionConfig' . $namespace . '>'
                . '<CallerReference>' . self::xmlEscape($spec['caller_reference']) . '</CallerReference>'
                . '<Aliases><Quantity>0</Quantity></Aliases>'
                . '<DefaultRootObject></DefaultRootObject>'
                . '<Origins><Quantity>1</Quantity><Items><Origin>'
                . '<Id>railway-origin</Id>'
                . '<DomainName>' . self::xmlEscape($spec['origin_domain']) . '</DomainName>'
                . '<OriginPath>' . self::xmlEscape($spec['origin_path']) . '</OriginPath>'
                . '<CustomHeaders><Quantity>0</Quantity></CustomHeaders>'
                . '<CustomOriginConfig>'
                . '<HTTPPort>80</HTTPPort><HTTPSPort>443</HTTPSPort>'
                . '<OriginProtocolPolicy>https-only</OriginProtocolPolicy>'
                . '<OriginSslProtocols><Quantity>1</Quantity><Items><SslProtocol>TLSv1.2</SslProtocol></Items></OriginSslProtocols>'
                . '<OriginReadTimeout>30</OriginReadTimeout><OriginKeepaliveTimeout>5</OriginKeepaliveTimeout>'
                . '</CustomOriginConfig>'
                . '<ConnectionAttempts>3</ConnectionAttempts><ConnectionTimeout>10</ConnectionTimeout>'
                . '</Origin></Items></Origins>'
                . '<OriginGroups><Quantity>0</Quantity></OriginGroups>'
                . '<DefaultCacheBehavior>'
                . '<TargetOriginId>railway-origin</TargetOriginId>'
                . '<TrustedSigners><Enabled>false</Enabled><Quantity>0</Quantity></TrustedSigners>'
                . '<TrustedKeyGroups><Enabled>false</Enabled><Quantity>0</Quantity></TrustedKeyGroups>'
                . '<ViewerProtocolPolicy>redirect-to-https</ViewerProtocolPolicy>'
                . '<AllowedMethods><Quantity>7</Quantity><Items>'
                . '<Method>GET</Method><Method>HEAD</Method><Method>OPTIONS</Method><Method>PUT</Method>'
                . '<Method>PATCH</Method><Method>POST</Method><Method>DELETE</Method>'
                . '</Items><CachedMethods><Quantity>2</Quantity><Items><Method>GET</Method><Method>HEAD</Method></Items></CachedMethods>'
                . '</AllowedMethods>'
                . '<SmoothStreaming>false</SmoothStreaming><Compress>true</Compress>'
                . '<LambdaFunctionAssociations><Quantity>0</Quantity></LambdaFunctionAssociations>'
                . '<FunctionAssociations><Quantity>0</Quantity></FunctionAssociations>'
                . '<FieldLevelEncryptionId></FieldLevelEncryptionId>'
                . '<CachePolicyId>' . self::CACHE_POLICY_DISABLED . '</CachePolicyId>'
                . '<OriginRequestPolicyId>' . self::ORIGIN_REQUEST_POLICY_ALL_EXCEPT_HOST . '</OriginRequestPolicyId>'
                . '</DefaultCacheBehavior>'
                . '<CacheBehaviors><Quantity>0</Quantity></CacheBehaviors>'
                . '<CustomErrorResponses><Quantity>0</Quantity></CustomErrorResponses>'
                . '<Comment>' . self::xmlEscape($spec['comment']) . '</Comment>'
                . '<Logging><Enabled>false</Enabled><IncludeCookies>false</IncludeCookies><Bucket></Bucket><Prefix></Prefix></Logging>'
                . '<PriceClass>' . $spec['price_class'] . '</PriceClass>'
                . '<Enabled>true</Enabled>'
                . '<ViewerCertificate><CloudFrontDefaultCertificate>true</CloudFrontDefaultCertificate></ViewerCertificate>'
                . '<Restrictions><GeoRestriction><RestrictionType>none</RestrictionType><Quantity>0</Quantity></GeoRestriction></Restrictions>'
                . '<WebACLId></WebACLId><HttpVersion>http2and3</HttpVersion>'
                . '<IsIPV6Enabled>' . ($spec['ipv6_enabled'] ? 'true' : 'false') . '</IsIPV6Enabled>'
                . '<Staging>false</Staging>'
                . '</DistributionConfig>';
        }

        private static function assertCredentialReference(string $credentialRef): void
        {
            // 连字符和大小写归一化都会产生环境变量碰撞，因此与账号表统一为大写 SNAKE_CASE。
            if (preg_match('/^[A-Z][A-Z0-9_]{0,63}$/', $credentialRef) !== 1) {
                throw new InvalidArgumentException('credential_ref 只允许 1-64 位大写字母、数字和下划线，且以字母开头');
            }
        }

        private static function normalizeCallerReference(string $value): string
        {
            $value = trim($value);
            if (preg_match('/^[A-Za-z0-9._:@+=\/-]{1,128}$/', $value) !== 1) {
                throw new InvalidArgumentException('caller_reference 格式不正确');
            }
            return $value;
        }

        /**
         * 规范化账本资源令牌。
         *
         * resource_token 会同时写入 CloudFront Comment 和 Tag，作为清理时的
         * 第二个所有权锚点；限制字符集和长度可避免 XML 注入、正则歧义及评论
         * 超过 CloudFront 128 字符上限。该方法只返回规范化后的令牌，不记录其内容。
         */
        private static function normalizeResourceToken(string $value): string
        {
            $value = trim($value);
            if (preg_match('/^[A-Za-z0-9._:@+=\/-]{1,' . self::MAX_RESOURCE_TOKEN_LENGTH . '}$/', $value) !== 1) {
                throw new InvalidArgumentException('resource_token 格式不正确');
            }
            return $value;
        }

        private static function resourceTokenForCallerReference(string $callerReference): string
        {
            return substr(hash('sha256', $callerReference), 0, 32);
        }

        private static function resourceTokenFromComment(string $comment, string $fallback): string
        {
            if (preg_match('/(?:^|\|)resource_token=([A-Za-z0-9._:@+=\/-]{1,' . self::MAX_RESOURCE_TOKEN_LENGTH . '})(?:\||$)/', $comment, $matches) === 1) {
                return $matches[1];
            }
            return $fallback;
        }

        private static function normalizeDistributionId(string $value): string
        {
            $value = trim($value);
            if (preg_match('/^[A-Z0-9]{5,64}$/', $value) !== 1) {
                throw new InvalidArgumentException('CloudFront Distribution ID 格式不正确');
            }
            return $value;
        }

        private static function normalizeEtag(string $value): string
        {
            $value = trim($value, " \t\r\n\"");
            return preg_match('/^[A-Za-z0-9._:-]{1,256}$/', $value) === 1 ? $value : '';
        }

        private static function assertHostname(string $hostname): void
        {
            if ($hostname === '' || strlen($hostname) > 253 || strpos($hostname, '..') !== false
                || preg_match('/^[a-z0-9.-]+$/', $hostname) !== 1) {
                throw new InvalidArgumentException('origin_domain 必须是不带协议、路径和端口的域名');
            }
            foreach (explode('.', $hostname) as $label) {
                if ($label === '' || strlen($label) > 63 || preg_match('/^[a-z0-9](?:[a-z0-9-]*[a-z0-9])?$/', $label) !== 1) {
                    throw new InvalidArgumentException('origin_domain 标签格式不正确');
                }
            }
        }

        private static function normalizeBoolean($value): bool
        {
            if ($value === true || $value === 1 || $value === '1' || $value === 'true') return true;
            if ($value === false || $value === 0 || $value === '0' || $value === 'false') return false;
            throw new InvalidArgumentException('布尔参数必须是 true/false 或 0/1');
        }

        private static function limitUtf8(string $value, int $maximum): string
        {
            if (preg_match_all('/./us', $value, $matches) === false) return '';
            return implode('', array_slice($matches[0], 0, $maximum));
        }

        private static function xmlEscape(string $value): string
        {
            return htmlspecialchars($value, ENT_QUOTES | ENT_XML1, 'UTF-8');
        }

        private static function normalizeHeaderValue(string $value): string
        {
            if (preg_match('/[\r\n\0]/', $value) === 1) {
                throw new AwsCloudFrontAdapterException('invalid_request_header');
            }
            return preg_replace('/[ \t]+/', ' ', trim($value)) ?? '';
        }

        private static function canonicalPath(string $path): string
        {
            if ($path === '' || $path[0] !== '/') throw new AwsCloudFrontAdapterException('invalid_request_path');
            $segments = explode('/', $path);
            foreach ($segments as &$segment) $segment = rawurlencode(rawurldecode($segment));
            unset($segment);
            return implode('/', $segments);
        }

        private static function canonicalQuery(array $params): string
        {
            $pairs = [];
            foreach ($params as $key => $value) {
                if ($value === null) continue;
                if (!is_scalar($value)) throw new AwsCloudFrontAdapterException('invalid_query_parameter');
                $pairs[] = [rawurlencode((string)$key), rawurlencode((string)$value)];
            }
            usort($pairs, static function (array $left, array $right): int {
                $keyOrder = strcmp($left[0], $right[0]);
                return $keyOrder !== 0 ? $keyOrder : strcmp($left[1], $right[1]);
            });
            $output = [];
            foreach ($pairs as $pair) $output[] = $pair[0] . '=' . $pair[1];
            return implode('&', $output);
        }

        /**
         * 受限 XML 结构校验：拒绝 DTD/实体，检查标签嵌套，并只解析 AWS 固定响应的元素文本。
         * 这条路径不调用 SimpleXML/DOM，因此不引入额外生产扩展依赖。
         */
        private static function assertSafeXml(string $xml): void
        {
            if ($xml === '' || strlen($xml) > self::MAX_RESPONSE_BYTES
                || preg_match('/<!\s*(?:DOCTYPE|ENTITY)\b/i', $xml) === 1) {
                throw new AwsCloudFrontAdapterException('unsafe_or_invalid_xml');
            }
            if (preg_match_all('/<[^>]+>/s', $xml, $matches) === false) {
                throw new AwsCloudFrontAdapterException('unsafe_or_invalid_xml');
            }
            $stack = [];
            $rootCount = 0;
            foreach ($matches[0] as $tag) {
                if (strncmp($tag, '<?', 2) === 0 || strncmp($tag, '<!--', 4) === 0 || strncmp($tag, '<![CDATA[', 9) === 0) continue;
                if (strncmp($tag, '<!', 2) === 0) throw new AwsCloudFrontAdapterException('unsafe_or_invalid_xml');
                if (preg_match('/^<\/\s*([A-Za-z_][A-Za-z0-9_.:-]*)\s*>$/', $tag, $closing) === 1) {
                    $name = self::xmlLocalName($closing[1]);
                    $open = array_pop($stack);
                    if ($open === null || $open !== $name) throw new AwsCloudFrontAdapterException('unsafe_or_invalid_xml');
                    continue;
                }
                if (preg_match('/^<\s*([A-Za-z_][A-Za-z0-9_.:-]*)(?:\s[^<>]*)?\/\s*>$/s', $tag, $selfClosing) === 1) {
                    if (count($stack) === 0) $rootCount++;
                    continue;
                }
                if (preg_match('/^<\s*([A-Za-z_][A-Za-z0-9_.:-]*)(?:\s[^<>]*)?>$/s', $tag, $opening) !== 1) {
                    throw new AwsCloudFrontAdapterException('unsafe_or_invalid_xml');
                }
                if (count($stack) === 0) $rootCount++;
                $stack[] = self::xmlLocalName($opening[1]);
            }
            if (count($stack) !== 0 || $rootCount !== 1) throw new AwsCloudFrontAdapterException('unsafe_or_invalid_xml');
        }

        private static function xmlLocalName(string $qualifiedName): string
        {
            $position = strrpos($qualifiedName, ':');
            return $position === false ? $qualifiedName : substr($qualifiedName, $position + 1);
        }

        /** @return array{0:int,1:int}|null */
        private static function xmlElementRange(string $xml, string $name): ?array
        {
            if (preg_match_all('/<[^>]+>/s', $xml, $matches, PREG_OFFSET_CAPTURE) === false) return null;
            $depth = 0;
            $start = null;
            foreach ($matches[0] as $captured) {
                $tag = $captured[0];
                $offset = $captured[1];
                if (strncmp($tag, '<?', 2) === 0 || strncmp($tag, '<!', 2) === 0) continue;
                if (preg_match('/^<\/\s*([A-Za-z_][A-Za-z0-9_.:-]*)\s*>$/', $tag, $closing) === 1) {
                    if ($start !== null) {
                        $depth--;
                        if ($depth === 0 && self::xmlLocalName($closing[1]) === $name) return [$start, $offset];
                    }
                    continue;
                }
                if (preg_match('/^<\s*([A-Za-z_][A-Za-z0-9_.:-]*)(?:\s[^<>]*)?\/\s*>$/s', $tag, $selfClosing) === 1) {
                    if ($start === null && self::xmlLocalName($selfClosing[1]) === $name) {
                        return [$offset + strlen($tag), $offset + strlen($tag)];
                    }
                    continue;
                }
                if (preg_match('/^<\s*([A-Za-z_][A-Za-z0-9_.:-]*)(?:\s[^<>]*)?>$/s', $tag, $opening) !== 1) continue;
                $local = self::xmlLocalName($opening[1]);
                if ($start === null && $local === $name) {
                    $start = $offset + strlen($tag);
                    $depth = 1;
                } elseif ($start !== null) {
                    $depth++;
                }
            }
            return null;
        }

        private static function xmlElementInner(string $xml, string $name): string
        {
            $range = self::xmlElementRange($xml, $name);
            if ($range === null) throw new AwsCloudFrontAdapterException('invalid_xml_response');
            return substr($xml, $range[0], $range[1] - $range[0]);
        }

        /** @return array<int,string> */
        private static function xmlElementInners(string $xml, string $name): array
        {
            $output = [];
            $offset = 0;
            while ($offset < strlen($xml)) {
                $slice = substr($xml, $offset);
                $range = self::xmlElementRange($slice, $name);
                if ($range === null) break;
                $output[] = substr($slice, $range[0], $range[1] - $range[0]);
                $offset += $range[1] + strlen('</' . $name . '>');
            }
            return $output;
        }

        /** @return array{0:int,1:int}|null */
        private static function xmlDirectChildRange(string $parentInner, string $childName): ?array
        {
            if (preg_match_all('/<[^>]+>/s', $parentInner, $matches, PREG_OFFSET_CAPTURE) === false) return null;
            $depth = 0;
            $start = null;
            foreach ($matches[0] as $captured) {
                $tag = $captured[0];
                $offset = $captured[1];
                if (strncmp($tag, '<?', 2) === 0 || strncmp($tag, '<!', 2) === 0) continue;
                if (preg_match('/^<\/\s*([A-Za-z_][A-Za-z0-9_.:-]*)\s*>$/', $tag, $closing) === 1) {
                    $depth--;
                    if ($start !== null && $depth === 0 && self::xmlLocalName($closing[1]) === $childName) {
                        return [$start, $offset];
                    }
                    continue;
                }
                if (preg_match('/^<\s*([A-Za-z_][A-Za-z0-9_.:-]*)(?:\s[^<>]*)?\/\s*>$/s', $tag, $selfClosing) === 1) {
                    if ($depth === 0 && self::xmlLocalName($selfClosing[1]) === $childName) {
                        return [$offset + strlen($tag), $offset + strlen($tag)];
                    }
                    continue;
                }
                if (preg_match('/^<\s*([A-Za-z_][A-Za-z0-9_.:-]*)(?:\s[^<>]*)?>$/s', $tag, $opening) !== 1) continue;
                $local = self::xmlLocalName($opening[1]);
                if ($depth === 0 && $local === $childName) $start = $offset + strlen($tag);
                $depth++;
            }
            return null;
        }

        private static function xmlDirectChildInner(string $parentInner, string $childName): ?string
        {
            $range = self::xmlDirectChildRange($parentInner, $childName);
            return $range === null ? null : substr($parentInner, $range[0], $range[1] - $range[0]);
        }

        private static function xmlDirectChildValue(string $parentInner, string $childName): string
        {
            $inner = self::xmlDirectChildInner($parentInner, $childName);
            if ($inner === null) return '';
            $text = preg_replace('/<!\[CDATA\[(.*?)\]\]>/s', '$1', $inner) ?? '';
            $text = strip_tags($text);
            return trim(html_entity_decode($text, ENT_QUOTES | ENT_XML1, 'UTF-8'));
        }

        private static function replaceDirectXmlChildText(
            string $xml,
            string $parentName,
            string $childName,
            string $replacement
        ): string {
            $parentRange = self::xmlElementRange($xml, $parentName);
            if ($parentRange === null) throw new AwsCloudFrontAdapterException('invalid_xml_response');
            $parentInner = substr($xml, $parentRange[0], $parentRange[1] - $parentRange[0]);
            $childRange = self::xmlDirectChildRange($parentInner, $childName);
            if ($childRange === null) throw new AwsCloudFrontAdapterException('invalid_xml_response');
            $absoluteStart = $parentRange[0] + $childRange[0];
            return substr_replace($xml, self::xmlEscape($replacement), $absoluteStart, $childRange[1] - $childRange[0]);
        }

        private static function responseRequestId(array $headers, string $xml): string
        {
            foreach (['x-amzn-requestid', 'x-amz-request-id', 'x-amzn-request-id'] as $name) {
                if (!empty($headers[$name])) {
                    $headerValue = substr((string)$headers[$name], 0, 128);
                    return preg_match('/^[A-Za-z0-9._:-]{1,128}$/', $headerValue) === 1 ? $headerValue : '';
                }
            }
            if ($xml === '') return '';
            $value = '';
            try {
                $range = self::xmlElementRange($xml, 'RequestId');
                if ($range !== null) $value = self::xmlDirectText(substr($xml, $range[0], $range[1] - $range[0]));
            } catch (Throwable $ignored) {
                $value = '';
            }
            $value = substr($value, 0, 128);
            return preg_match('/^[A-Za-z0-9._:-]{1,128}$/', $value) === 1 ? $value : '';
        }

        private static function xmlDirectText(string $inner): string
        {
            return trim(html_entity_decode(strip_tags($inner), ENT_QUOTES | ENT_XML1, 'UTF-8'));
        }

        private static function awsErrorCode(string $xml): string
        {
            if ($xml === '') return '';
            try {
                $errorInner = self::xmlElementInner($xml, 'Error');
                $code = self::xmlDirectChildValue($errorInner, 'Code');
                return preg_match('/^[A-Za-z0-9._-]{1,128}$/', $code) === 1 ? $code : '';
            } catch (Throwable $ignored) {
                return '';
            }
        }

        private static function reasonCodeForAwsError(string $awsCode, int $status): string
        {
            $known = [
                'DistributionAlreadyExists' => 'distribution_already_exists',
                'NoSuchDistribution' => 'distribution_not_found',
                'DistributionNotDisabled' => 'distribution_still_enabled',
                'PreconditionFailed' => 'stale_etag',
                'InvalidIfMatchVersion' => 'stale_etag',
                'AccessDenied' => 'aws_access_denied',
                'InvalidClientTokenId' => 'aws_credentials_invalid',
                'SignatureDoesNotMatch' => 'aws_signature_mismatch',
                'ExpiredToken' => 'aws_session_expired',
                'Throttling' => 'aws_throttled',
                'ThrottlingException' => 'aws_throttled',
            ];
            if (isset($known[$awsCode])) return $known[$awsCode];
            if ($awsCode !== '') {
                $snake = strtolower(preg_replace('/(?<!^)[A-Z]/', '_$0', $awsCode) ?? 'request_failed');
                $snake = preg_replace('/[^a-z0-9_]+/', '_', $snake) ?? 'request_failed';
                return 'aws_' . trim($snake, '_');
            }
            return $status === 429 ? 'aws_throttled' : 'aws_request_failed';
        }
    }
}
