-- 已安装系统的链接小时统计索引迁移；在目标业务库中执行，可重复运行。
-- 仅增加查询索引，不修改或删除历史点击数据。
SET @popup_click_index_exists = (
    SELECT COUNT(*) FROM information_schema.statistics
    WHERE table_schema = DATABASE()
      AND table_name = 'cainiao_popup_stat_log'
      AND index_name = 'idx_popup_stat_hourly'
);
SET @popup_click_index_sql = IF(
    @popup_click_index_exists > 0,
    'SELECT 1',
    'CREATE INDEX idx_popup_stat_hourly ON cainiao_popup_stat_log (popup_id, module, type, created_at)'
);
PREPARE popup_click_index_stmt FROM @popup_click_index_sql;
EXECUTE popup_click_index_stmt;
DEALLOCATE PREPARE popup_click_index_stmt;
