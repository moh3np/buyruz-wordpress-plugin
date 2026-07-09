/**
 * Front-end JS for Advanced Sidebar Filters.
 * Handles dual-range sliders, AJAX loading, History API and Debouncing.
 * Supports multiple independent widget instances.
 * 
 * هشدار: پیش از هر تغییر، حتماً فایل CONTRIBUTING.md را با دقت کامل بخوانید و بی‌قید و شرط اجرا کنید و پس از اتمام کار تطابق را دوباره چک کنید.
 */

document.addEventListener('DOMContentLoaded', function() {
    // Config options passed from WordPress localize script
    const config = window.brzFiltersConfig || {
        container_selector: '.products-box',
        pagination_selector: '.woocommerce-pagination',
        count_selector: '.woocommerce-result-count',
        ajax_enabled: true,
        push_state: true
    };

    // Initialize all range sliders
    const rangeSliders = document.querySelectorAll('.brz-range-slider-wrapper');
    rangeSliders.forEach(function(wrapper) {
        initDualRangeSlider(wrapper);
    });

    // Setup events
    setupFilterEvents(config);
});

/**
 * Initialize RTL Dual Range Slider.
 */
function initDualRangeSlider(wrapper) {
    const minInput = wrapper.querySelector('.brz-range-input-min');
    const maxInput = wrapper.querySelector('.brz-range-input-max');
    const minValText = wrapper.querySelector('.brz-range-value-min');
    const maxValText = wrapper.querySelector('.brz-range-value-max');
    
    let activeTrack = wrapper.querySelector('.brz-range-slider-track-active');
    if (!activeTrack) {
        activeTrack = document.createElement('div');
        activeTrack.className = 'brz-range-slider-track-active';
        wrapper.querySelector('.brz-range-slider-track-container').appendChild(activeTrack);
    }

    const minLimit = parseFloat(minInput.min);
    const maxLimit = parseFloat(maxInput.max);
    const prefix = minValText.textContent.replace(/[0-9\s]/g, '').trim();
    const suffix = maxValText.textContent.replace(/[0-9\s]/g, '').trim();

    function updateTrack() {
        let minVal = parseFloat(minInput.value);
        let maxVal = parseFloat(maxInput.value);

        // Prevent cross-over
        if (minVal > maxVal) {
            minInput.value = maxVal;
            minVal = maxVal;
        }

        const range = maxLimit - minLimit;
        const minPercent = range > 0 ? ((minVal - minLimit) / range) * 100 : 0;
        const maxPercent = range > 0 ? ((maxVal - minLimit) / range) * 100 : 100;

        // In RTL, the track active portion starts from right (minPercent) to left (maxPercent)
        activeTrack.style.right = minPercent + '%';
        activeTrack.style.left = (100 - maxPercent) + '%';

        minValText.textContent = (prefix ? prefix + ' ' : '') + Math.round(minVal) + (suffix ? ' ' + suffix : '');
        maxValText.textContent = (prefix ? prefix + ' ' : '') + Math.round(maxVal) + (suffix ? ' ' + suffix : '');
    }

    minInput.addEventListener('input', updateTrack);
    maxInput.addEventListener('input', updateTrack);

    updateTrack();
}

/**
 * Capture filter control events and handle page updates.
 */
function setupFilterEvents(config) {
    let debounceTimer = null;

    function debounce(func, delay) {
        return function(...args) {
            clearTimeout(debounceTimer);
            debounceTimer = setTimeout(() => func.apply(this, args), delay);
        };
    }

    const debouncedFetch = debounce(fetchFilteredProducts, 300);

    // 1. Capture changes in our custom widgets
    document.body.addEventListener('change', function(e) {
        const control = e.target.closest('.brz-filter-widget-control');
        if (!control) return;

        if (e.target.type === 'checkbox' && !control.classList.contains('brz-filter-type-boolean')) {
            fetchFilteredProducts();
        } else if (e.target.type === 'number') {
            debouncedFetch();
        } else {
            fetchFilteredProducts();
        }
    });

    document.body.addEventListener('input', function(e) {
        const control = e.target.closest('.brz-filter-widget-control');
        if (control && e.target.type === 'range') {
            debouncedFetch();
        }
    });

    // 2. Intercept standard WooCommerce attribute widgets and categories clicks to unify AJAX filtering
    document.body.addEventListener('click', function(e) {
        if (!config.ajax_enabled) return;

        // Intercept standard woocommerce attribute links, category links, or reset links in sidebar
        const sidebarLink = e.target.closest('.filters-panel a, .widget-area a, .widget_layered_nav a, .widget_product_categories a');
        if (sidebarLink) {
            const url = sidebarLink.getAttribute('href');
            // Check if it's a valid link and relates to query string
            if (url && (url.includes('?') || url.includes('product-category') || sidebarLink.classList.contains('action-reset') || sidebarLink.id === 'reset-filtering')) {
                e.preventDefault();
                fetchFilteredProducts(url);
                
                // If it's a mobile filter panel close or reset link, trigger theme closing
                if (sidebarLink.id === 'reset-filtering' || sidebarLink.classList.contains('action-reset')) {
                    const closeBtn = document.querySelector('.close_filter_panels');
                    if (closeBtn) closeBtn.click();
                }
            }
        }
    });

    // 3. Intercept pagination click events
    bindPaginationLinks(config);

    // 4. Support browser back/forward buttons
    window.addEventListener('popstate', function(e) {
        fetchFilteredProducts(window.location.href, false);
    });

    /**
     * Intercept clicks on pagination.
     */
    function bindPaginationLinks(cfg) {
        const productsContainer = document.querySelector(cfg.container_selector);
        if (!productsContainer) return;

        productsContainer.addEventListener('click', function(e) {
            const link = e.target.closest(cfg.pagination_selector + ' a');
            if (link) {
                e.preventDefault();
                fetchFilteredProducts(link.getAttribute('href'));
                productsContainer.scrollIntoView({ behavior: 'smooth', block: 'start' });
            }
        });
    }

    /**
     * Read filter parameters from our widgets.
     */
    function getFilterQueryParameters() {
        const params = new URLSearchParams();
        const activeControls = document.querySelectorAll('.brz-filter-widget-control');

        activeControls.forEach(function(control) {
            const key = control.getAttribute('data-key');
            const type = control.className.split(' ').find(c => c.startsWith('brz-filter-type-')).replace('brz-filter-type-', '');

            if ('range' === type) {
                const minInput = control.querySelector('.brz-range-input-min');
                const maxInput = control.querySelector('.brz-range-input-max');
                if (minInput && maxInput) {
                    const minLimit = minInput.getAttribute('min');
                    const maxLimit = maxInput.getAttribute('max');
                    // Only send parameters if they differ from boundaries to keep URL clean
                    if (minInput.value !== minLimit) {
                        params.set(key + '_min', minInput.value);
                    }
                    if (maxInput.value !== maxLimit) {
                        params.set(key + '_max', maxInput.value);
                    }
                }
            } else if ('array' === type) {
                const checked = Array.from(control.querySelectorAll('input[type="checkbox"]:checked')).map(el => el.value);
                if (checked.length > 0) {
                    params.set(key, checked.join(','));
                }
            } else if ('boolean' === type) {
                const checkbox = control.querySelector('input[type="checkbox"]');
                if (checkbox && checkbox.checked) {
                    params.set(key, '1');
                }
            } else if ('integer' === type || 'decimal' === type) {
                const minInput = control.querySelector('[data-suffix="_min"]');
                const maxInput = control.querySelector('[data-suffix="_max"]');
                if (minInput && minInput.value !== '') {
                    params.set(key + '_min', minInput.value);
                }
                if (maxInput && maxInput.value !== '') {
                    params.set(key + '_max', maxInput.value);
                }
            }
        });

        return params;
    }

    /**
     * Synchronize widget states with active URL.
     */
    function syncWidgetStatesFromUrl(urlStr) {
        const url = new URL(urlStr, window.location.origin);
        const params = new URLSearchParams(url.search);
        const activeControls = document.querySelectorAll('.brz-filter-widget-control');

        activeControls.forEach(function(control) {
            const key = control.getAttribute('data-key');
            const type = control.className.split(' ').find(c => c.startsWith('brz-filter-type-')).replace('brz-filter-type-', '');

            if ('range' === type) {
                const minInput = control.querySelector('.brz-range-input-min');
                const maxInput = control.querySelector('.brz-range-input-max');
                if (minInput && maxInput) {
                    const urlMin = params.get(key + '_min');
                    const urlMax = params.get(key + '_max');
                    minInput.value = urlMin !== null ? urlMin : minInput.getAttribute('min');
                    maxInput.value = urlMax !== null ? urlMax : maxInput.getAttribute('max');
                    initDualRangeSlider(control);
                }
            } else if ('array' === type) {
                const checkboxes = control.querySelectorAll('input[type="checkbox"]');
                const urlVal = params.get(key);
                const vals = urlVal ? urlVal.split(',') : [];
                checkboxes.forEach(function(checkbox) {
                    checkbox.checked = vals.includes(checkbox.value);
                });
            } else if ('boolean' === type) {
                const checkbox = control.querySelector('input[type="checkbox"]');
                if (checkbox) {
                    const urlVal = params.get(key);
                    checkbox.checked = (urlVal === '1' || urlVal === 'true');
                }
            } else if ('integer' === type || 'decimal' === type) {
                const minInput = control.querySelector('[data-suffix="_min"]');
                const maxInput = control.querySelector('[data-suffix="_max"]');
                if (minInput) minInput.value = params.get(key + '_min') || '';
                if (maxInput) maxInput.value = params.get(key + '_max') || '';
            }
        });
    }

    /**
     * Main AJAX AJAX loader.
     */
    function fetchFilteredProducts(targetUrl = null, updateHistory = true) {
        const productsContainer = document.querySelector(config.container_selector);
        if (!productsContainer) return;

        // 1. Check if AJAX is enabled
        let fetchUrl = targetUrl;
        if (!fetchUrl) {
            const widgetParams = getFilterQueryParameters();
            
            // Build current url merging our parameters
            const url = new URL(window.location.href);
            const params = url.searchParams;

            // Clear our existing spec filter variables from URL
            const activeControls = document.querySelectorAll('.brz-filter-widget-control');
            activeControls.forEach(control => {
                const key = control.getAttribute('data-key');
                params.delete(key);
                params.delete(key + '_min');
                params.delete(key + '_max');
            });
            params.delete('paged'); // reset page to 1 on filter change

            // Add our active widget parameters
            widgetParams.forEach((value, name) => {
                params.set(name, value);
            });

            fetchUrl = url.pathname + (params.toString() ? '?' + params.toString() : '');
        }

        // If AJAX is disabled, reload page with the new URL parameters
        if (!config.ajax_enabled) {
            window.location.href = fetchUrl;
            return;
        }

        // 2. Display loader overlay
        let overlay = productsContainer.querySelector('.brz-ajax-loading-overlay');
        if (!overlay) {
            overlay = document.createElement('div');
            overlay.className = 'brz-ajax-loading-overlay';
            const spinner = document.createElement('div');
            spinner.className = 'brz-filters-spinner';
            overlay.appendChild(spinner);
            
            if (window.getComputedStyle(productsContainer).position === 'static') {
                productsContainer.classList.add('brz-ajax-loading-container');
            }
            productsContainer.appendChild(overlay);
        }

        overlay.classList.add('active');

        // 3. Request URL content
        fetch(fetchUrl)
            .then(response => {
                if (!response.ok) throw new Error('HTTP error ' + response.status);
                return response.text();
            })
            .then(html => {
                const parser = new DOMParser();
                const doc = parser.parseFromString(html, 'text/html');

                // Swap products container HTML
                const newProducts = doc.querySelector(config.container_selector);
                const currentProducts = document.querySelector(config.container_selector);
                if (newProducts && currentProducts) {
                    currentProducts.innerHTML = newProducts.innerHTML;
                }

                // Swap result count HTML
                const newCount = doc.querySelector(config.count_selector);
                const currentCount = document.querySelector(config.count_selector);
                if (newCount && currentCount) {
                    currentCount.innerHTML = newCount.innerHTML;
                }

                // Sync widget UI controls to reflect the URL changes
                syncWidgetStatesFromUrl(fetchUrl);

                // Update navigation history
                if (config.push_state && updateHistory) {
                    window.history.pushState({ path: fetchUrl }, '', fetchUrl);
                }

                // Re-bind pagination clicks
                bindPaginationLinks(config);

                // Hide loader
                overlay.classList.remove('active');

                // Fire custom event
                document.body.dispatchEvent(new CustomEvent('brz_filters_updated', { detail: { url: fetchUrl } }));

                // Mobile panel auto-close if triggered via apply button
                const closeBtn = document.querySelector('.close_filter_panels');
                if (closeBtn && window.getComputedStyle(closeBtn).display !== 'none') {
                    closeBtn.click();
                }
            })
            .catch(error => {
                console.error('Filtering failed: ', error);
                overlay.classList.remove('active');
            });
    }
}
