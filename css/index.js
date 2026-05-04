document.addEventListener('DOMContentLoaded', () => {
            const themeToggle = document.querySelector('.theme-toggle');
            const sunIcon = document.querySelector('.sun-icon');
            const moonIcon = document.querySelector('.moon-icon');
            
            // 检查本地存储中的主题设置，默认为 light
            const savedTheme = localStorage.getItem('theme') || 'light';
            if (savedTheme === 'light' || !savedTheme) {
                document.body.classList.add('light-theme');
                sunIcon.style.display = 'none';
                moonIcon.style.display = 'block';
            } else {
                sunIcon.style.display = 'block';
                moonIcon.style.display = 'none';
            }

            // 切换主题
            themeToggle.addEventListener('click', () => {
                document.body.classList.toggle('light-theme');
                const isLight = document.body.classList.contains('light-theme');
                
                // 更新图标显示
                sunIcon.style.display = isLight ? 'none' : 'block';
                moonIcon.style.display = isLight ? 'block' : 'none';
                
                // 保存主题设置到本地存储
                localStorage.setItem('theme', isLight ? 'light' : 'dark');
            });
        });

        // 存储动画状态
        const animationState = {
            isAnimating: false,
            hasAnimated: false
        };

        // 改进的数字动画函数
        function animateNumber(element, start, end, duration) {
            // 如果已经完成动画，直接返回
            if (element.dataset.completed === 'true') {
                return;
            }

            const isDecimal = element.dataset.decimal === 'true';
            const suffix = element.dataset.suffix || '';
            let startTimestamp = null;
            let lastValue = null;
            
            const easeOutQuart = t => 1 - Math.pow(1 - t, 4);
            
            const step = (timestamp) => {
                if (!startTimestamp) startTimestamp = timestamp;
                const progress = Math.min((timestamp - startTimestamp) / duration, 1);
                const easedProgress = easeOutQuart(progress);
                
                let current;
                if (isDecimal) {
                    current = (easedProgress * (end - start) + start).toFixed(1);
                } else {
                    current = Math.floor(easedProgress * (end - start) + start);
                    
                    if (lastValue === current) {
                        if (progress < 1) {
                            window.requestAnimationFrame(step);
                        }
                        return;
                    }
                    lastValue = current;
                    current = current.toLocaleString('zh-CN');
                }
                
                element.textContent = current + suffix;
                
                if (progress < 1) {
                    window.requestAnimationFrame(step);
                } else {
                    // 动画完成，设置最终值和完成标记
                    if (!isDecimal) {
                        element.textContent = end.toLocaleString('zh-CN') + suffix;
                    }
                    element.dataset.completed = 'true';
                    animationState.isAnimating = false;
                }
            };
            
            // 开始动画
            animationState.isAnimating = true;
            window.requestAnimationFrame(step);
        }

        // 优化的观察者逻辑
        const observer = new IntersectionObserver((entries) => {
            entries.forEach(entry => {
                if (entry.isIntersecting && !animationState.hasAnimated) {
                    const statNumbers = document.querySelectorAll('.stat-number:not([data-completed="true"])');
                    if (statNumbers.length > 0 && !animationState.isAnimating) {
                        statNumbers.forEach(number => {
                            const finalValue = parseFloat(number.dataset.value);
                            const isDecimal = number.dataset.decimal === 'true';
                            const startValue = isDecimal ? 0.0 : 0;
                            animateNumber(number, startValue, finalValue, 2500);
                        });
                        animationState.hasAnimated = true;
                        observer.disconnect(); // 停止观察
                    }
                }
            });
        }, {
            threshold: 0.5
        });

        // 初始化观察
        document.addEventListener('DOMContentLoaded', () => {
            const statsContainer = document.querySelector('.download-stats');
            const rect = statsContainer.getBoundingClientRect();
            
            if (rect.top < window.innerHeight && !animationState.hasAnimated) {
                // 如果在视口中且未动画，立即开始动画
                const statNumbers = document.querySelectorAll('.stat-number:not([data-completed="true"])');
                statNumbers.forEach(number => {
                    const finalValue = parseFloat(number.dataset.value);
                    const isDecimal = number.dataset.decimal === 'true';
                    const startValue = isDecimal ? 0.0 : 0;
                    animateNumber(number, startValue, finalValue, 2500);
                });
                animationState.hasAnimated = true;
            } else {
                // 否则等待滚动到可视区域
                observer.observe(statsContainer);
            }
        });

        // 添加返回顶部按钮功能
        document.addEventListener('DOMContentLoaded', () => {
            const backToTop = document.querySelector('.back-to-top');
            
            // 监听滚动事件
            window.addEventListener('scroll', () => {
                if (window.scrollY > 300) {
                    backToTop.classList.add('visible');
                } else {
                    backToTop.classList.remove('visible');
                }
            });

            // 点击返回顶部
            backToTop.addEventListener('click', () => {
                window.scrollTo({
                    top: 0,
                    behavior: 'smooth'
                });
            });
        });

        // 轮播图功能
        document.addEventListener('DOMContentLoaded', () => {
            const track = document.querySelector('.carousel-track');
            const slides = document.querySelectorAll('.carousel-slide');
            const prevButton = document.querySelector('.carousel-button.prev');
            const nextButton = document.querySelector('.carousel-button.next');
            const indicatorsContainer = document.querySelector('.carousel-indicators');
            
            let currentIndex = 0;
            let autoplayInterval;
            const slideCount = slides.length;

            // 创建指示器
            slides.forEach((_, index) => {
                const indicator = document.createElement('div');
                indicator.classList.add('indicator');
                if (index === 0) indicator.classList.add('active');
                indicator.addEventListener('click', () => goToSlide(index));
                indicatorsContainer.appendChild(indicator);
            });

            const indicators = document.querySelectorAll('.indicator');

            function updateIndicators() {
                indicators.forEach((indicator, index) => {
                    indicator.classList.toggle('active', index === currentIndex);
                });
            }

            function goToSlide(index) {
                currentIndex = index;
                track.style.transform = `translateX(-${currentIndex * 100}%)`;
                updateIndicators();
            }

            function nextSlide() {
                currentIndex = (currentIndex + 1) % slideCount;
                goToSlide(currentIndex);
            }

            function prevSlide() {
                currentIndex = (currentIndex - 1 + slideCount) % slideCount;
                goToSlide(currentIndex);
            }

            // 自动播放
            function startAutoplay() {
                autoplayInterval = setInterval(nextSlide, 3500);
            }

            function stopAutoplay() {
                clearInterval(autoplayInterval);
            }

            // 事件监听
            prevButton.addEventListener('click', () => {
                prevSlide();
                stopAutoplay();
                startAutoplay();
            });

            nextButton.addEventListener('click', () => {
                nextSlide();
                stopAutoplay();
                startAutoplay();
            });

            // 触摸滑动支持
            let touchStartX = 0;
            let touchEndX = 0;

            track.addEventListener('touchstart', e => {
                touchStartX = e.touches[0].clientX;
                stopAutoplay();
            }, { passive: true });

            track.addEventListener('touchmove', e => {
                touchEndX = e.touches[0].clientX;
            }, { passive: true });

            track.addEventListener('touchend', () => {
                const difference = touchStartX - touchEndX;
                if (Math.abs(difference) > 50) {
                    if (difference > 0) {
                        nextSlide();
                    } else {
                        prevSlide();
                    }
                }
                startAutoplay();
            });

            // 鼠标悬停时暂停自动播放
            track.addEventListener('mouseenter', stopAutoplay);
            track.addEventListener('mouseleave', startAutoplay);

            // 开始自动播放
            startAutoplay();
        });

        document.addEventListener('DOMContentLoaded', () => {
            const cards = document.querySelectorAll('.feature-card');
            
            window.addEventListener('scroll', () => {
                cards.forEach(card => {
                    const rect = card.getBoundingClientRect();
                    const centerY = window.innerHeight / 2;
                    const cardCenterY = rect.top + rect.height / 2;
                    const distance = Math.abs(centerY - cardCenterY);
                    const maxDistance = window.innerHeight / 2;
                    const scale = Math.max(0.95, 1 - (distance / maxDistance) * 0.1);
                    
                    if (rect.top < window.innerHeight && rect.bottom > 0) {
                        card.style.transform = `scale(${scale}) translateZ(${20 * scale}px)`;
                        card.style.opacity = scale;
                    }
                });
            }, { passive: true });
            
            // 添加触摸反馈
            if ('ontouchstart' in window) {
                cards.forEach(card => {
                    card.addEventListener('touchstart', () => {
                        card.style.transform = 'scale(0.98)';
                    }, { passive: true });
                    
                    card.addEventListener('touchend', () => {
                        card.style.transform = '';
                    }, { passive: true });
                });
            }
        });

        // 优化滚动监听性能
        document.addEventListener('DOMContentLoaded', function() {
            const observer = new IntersectionObserver((entries, observer) => {
                entries.forEach(entry => {
                    if (entry.isIntersecting) {
                        entry.target.classList.add('active');
                        // 一旦元素显示，停止观察
                        observer.unobserve(entry.target);
                    }
                });
            }, {
                threshold: 0.1,
                rootMargin: '50px' // 提前开始加载
            });

            // 使用 requestAnimationFrame 优化 DOM 操作
            requestAnimationFrame(() => {
                document.querySelectorAll('.slide-fade-up').forEach((el) => {
                    observer.observe(el);
                });
            });
        });

        // 优化滚动事件处理
        let ticking = false;
        window.addEventListener('scroll', () => {
            if (!ticking) {
                requestAnimationFrame(() => {
                    // 滚动相关的操作
                    ticking = false;
                });
                ticking = true;
            }
        });

        // 图片加载优化
        document.querySelectorAll('img').forEach(img => {
            img.loading = 'lazy';
            img.decoding = 'async';
        });

        // 页面加载完成时滚动到顶部
        window.onload = function() {
            window.scrollTo({
                top: 0,
                behavior: 'instant' // 立即滚动，不使用平滑效果
            });
        };

        // 确保页面刷新时也会回到顶部
        if (history.scrollRestoration) {
            history.scrollRestoration = 'manual';
        }

        // 页面卸载前记录当前位置为顶部
        window.onbeforeunload = function() {
            window.scrollTo(0, 0);
        };

        // 使用 requestAnimationFrame 优化滚动事件
        let scrollTimeout;
        window.addEventListener('scroll', () => {
            if (!scrollTimeout) {
                scrollTimeout = requestAnimationFrame(() => {
                    // 执行滚动相关操作
                    scrollTimeout = null;
                });
            }
        }, { passive: true });

        // 优化图片加载
        document.addEventListener('DOMContentLoaded', () => {
            const images = document.querySelectorAll('img[loading="lazy"]');
            if ('loading' in HTMLImageElement.prototype) {
                images.forEach(img => {
                    img.loading = 'lazy';
                    img.decoding = 'async';
                });
            } else {
                // 回退方案：使用 Intersection Observer
                const imageObserver = new IntersectionObserver((entries, observer) => {
                    entries.forEach(entry => {
                        if (entry.isIntersecting) {
                            const img = entry.target;
                            img.src = img.dataset.src;
                            observer.unobserve(img);
                        }
                    });
                });

                images.forEach(img => {
                    imageObserver.observe(img);
                });
            }
        });

        // 优化动画性能
        const animationObserver = new IntersectionObserver((entries) => {
            entries.forEach(entry => {
                if (entry.isIntersecting) {
                    requestAnimationFrame(() => {
                        entry.target.classList.add('animate');
                    });
                }
            });
        }, { threshold: 0.1 });

        document.querySelectorAll('.feature-card, .partner-card').forEach(el => {
            animationObserver.observe(el);
        });

        // 优化轮播图性能
        const carousel = document.querySelector('.carousel-track');
        if (carousel) {
            carousel.addEventListener('touchstart', () => {
                carousel.style.willChange = 'transform';
            }, { passive: true });

            carousel.addEventListener('touchend', () => {
                setTimeout(() => {
                    carousel.style.willChange = 'auto';
                }, 100);
            }, { passive: true });
        }

        // 优化资源加载顺序
        document.addEventListener('DOMContentLoaded', () => {
            // 延迟加载非关键 JavaScript
            const deferScripts = document.querySelectorAll('script[defer-load]');
            requestIdleCallback(() => {
                deferScripts.forEach(script => {
                    const newScript = document.createElement('script');
                    newScript.src = script.getAttribute('data-src');
                    document.body.appendChild(newScript);
                });
            });

            // 延迟加载图片
            const lazyImages = document.querySelectorAll('img[data-src]');
            const imageObserver = new IntersectionObserver((entries) => {
                entries.forEach(entry => {
                    if (entry.isIntersecting) {
                        const img = entry.target;
                        img.src = img.dataset.src;
                        if (img.dataset.srcset) {
                            img.srcset = img.dataset.srcset;
                        }
                        img.classList.add('loaded');
                        imageObserver.unobserve(img);
                    }
                });
            }, {
                rootMargin: '50px 0px'
            });

            lazyImages.forEach(img => imageObserver.observe(img));
        });

        // 使用 requestIdleCallback 延迟加载非关键组件
        /*requestIdleCallback(() => {
            // 加载分析脚本
            const analyticsScript = document.createElement('script');
            analyticsScript.src = '/js/analytics.js';
            document.body.appendChild(analyticsScript);
            
            // 加载其他非关键功能
            loadNonCriticalFeatures();
        });*/

        function loadNonCriticalFeatures() {
            // 初始化非关键功能
            if ('IntersectionObserver' in window) {
                const sections = document.querySelectorAll('.lazy-section');
                const sectionObserver = new IntersectionObserver((entries) => {
                    entries.forEach(entry => {
                        if (entry.isIntersecting) {
                            entry.target.classList.add('loaded');
                            sectionObserver.unobserve(entry.target);
                        }
                    });
                });

                sections.forEach(section => sectionObserver.observe(section));
            }
        }