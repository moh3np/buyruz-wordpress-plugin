/**
 * Front-end JS for Advanced Sidebar Filters.
 * Handles dual-range sliders, AJAX loading, History API and Debouncing.
 * 
 * هشدار: پیش از هر تغییر، حتماً فایل CONTRIBUTING.md را با دقت کامل بخوانید و بی‌قید و شرط اجرا کنید و پس از اتمام کار تطابق را دوباره چک کنید.
 */

document.addEventListener('DOMContentLoaded', function() {
    const filterForm = document.querySelector('.brz-sidebar-filters-form');
    if (!filterForm) return;

    // Config options passed from WordPress localize script
    const config = window.brzFiltersConfig || {
        container_selector: '.products-box',
        pagination_selector: '.woocommerce-pagination',
        count_selector: '.woocommerce-result-count',
        ajax_enabled: true,
        push_state: true
    };

    // Initialize range sliders
    const rangeWrappers = filterForm.querySelectorAll('.brz-range-slider-wrapper');
    rangeWrappers.forEach(function(wrapper) {
        initDualRangeSlider(wrapper);
    });

    if (config.ajax_enabled) {
        filterForm.classList.add('ajax-live-active');
        initAjaxFiltering(filterForm, config);
    }
});

/**
 * Initialize RTL Dual Range Slider.
 */
function initDualRangeSlider(wrapper) {
    const minInput = wrapper.querySelector('.brz-range-input-min');
    const maxInput = wrapper.querySelector('.brz-range-input-max');
    const minValText = wrapper.querySelector('.brz-range-value-min');
    const maxValText = wrapper.querySelector('.brz-range-value-max');
    
    // Create active track element dynamically if not exists
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
        // So right distance is minPercent, left distance is 100 - maxPercent
        activeTrack.style.right = minPercent + '%';
        activeTrack.style.left = (100 - maxPercent) + '%';

        minValText.textContent = (prefix ? prefix + ' ' : '') + Math.round(minVal) + (suffix ? ' ' + suffix : '');
        maxValText.textContent = (prefix ? prefix + ' ' : '') + Math.round(maxVal) + (suffix ? ' ' + suffix : '');
    }

    minInput.addEventListener('input', updateTrack);
    maxInput.addEventListener('input', updateTrack);

    // Initial update
    updateTrack();
}

/**
 * Initialize AJAX and History logic.
 */
function initAjaxFiltering(form, config) {
    let debounceTimer = null;

    // Helper for debouncing AJAX calls
    function debounce(func, delay) {
        return function(...args) {
            clearTimeout(debounceTimer);
            debounceTimer = setTimeout(() => func.apply(this, args), delay);
        };
    }

    // Capture changes on any input
    form.addEventListener('change', function(e) {
        // For range/number inputs, use debounce to prevent spamming queries
        if (e.target.type === 'range' || e.target.type === 'number') {
            debouncedFetch();
        } else {
            fetchFilteredProducts();
        }
    });

    // Also listen to direct input events for sliders (with longer debounce)
    form.addEventListener('input', function(e) {
        if (e.target.type === 'range') {
            debouncedFetch();
        }
    });

    const debouncedFetch = debounce(fetchFilteredProducts, 300);

    // Intercept submit event (e.g. if form submitted via enter or fallback button)
    form.addEventListener('submit', function(e) {
        e.preventDefault();
        fetchFilteredProducts();
    });

    // Intercept reset button click
    const resetBtn = form.querySelector('.brz-filter-reset-btn');
    if (resetBtn) {
        resetBtn.addEventListener('click', function(e) {
            e.preventDefault();
            form.reset();
            // Clear slider displays
            const rangeWrappers = form.querySelectorAll('.brz-range-slider-wrapper');
            rangeWrappers.forEach(function(wrapper) {
                const minInput = wrapper.querySelector('.brz-range-input-min');
                const maxInput = wrapper.querySelector('.brz-range-input-max');
                minInput.value = minInput.min;
                maxInput.value = maxInput.max;
                initDualRangeSlider(wrapper);
            });
            fetchFilteredProducts(resetBtn.getAttribute('href'));
        });
    }

    // Intercept pagination clicks to load next pages via AJAX
    bindPaginationLinks(config);

    // Support browser back/forward buttons
    window.addEventListener('popstate', function(e) {
        fetchFilteredProducts(window.location.href, false);
    });

    /**
     * Bind click handler to pagination links.
     */
    function bindPaginationLinks(cfg) {
        const productsContainer = document.querySelector(cfg.container_selector);
        if (!productsContainer) return;

        productsContainer.addEventListener('click', function(e) {
            const link = e.target.closest(cfg.pagination_selector + ' a');
            if (link) {
                e.preventDefault();
                fetchFilteredProducts(link.getAttribute('href'));
                // Scroll to top of products list smoothly
                productsContainer.scrollIntoView({ behavior: 'smooth', block: 'start' });
            }
        });
    }

    /**
     * AJAX Fetcher.
     */
    function fetchFilteredProducts(targetUrl = null, updateHistory = true) {
        const productsContainer = document.querySelector(config.container_selector);
        if (!productsContainer) return;

        // 1. Create or show loading overlay
        let overlay = productsContainer.querySelector('.brz-ajax-loading-overlay');
        if (!overlay) {
            overlay = document.createElement('div');
            overlay.className = 'brz-ajax-loading-overlay';
            const spinner = document.createElement('div');
            spinner.className = 'brz-filters-spinner';
            overlay.appendChild(spinner);
            
            // Ensure products container is relative
            if (window.getComputedStyle(productsContainer).position === 'static') {
                productsContainer.classList.add('brz-ajax-loading-container');
            }
            productsContainer.appendChild(overlay);
        }

        // Show spinner
        overlay.classList.add('active');

        // 2. Build URL
        let fetchUrl = targetUrl;
        if (!fetchUrl) {
            const formData = new FormData(form);
            const params = new URLSearchParams();

            // Populate query params from form
            for (const [key, value] of formData.entries()) {
                if (value !== '') {
                    // Collect checkbox values as comma-separated or array
                    if (key.endsWith('[]')) {
                        const baseKey = key.slice(0, -2);
                        const current = params.get(baseKey);
                        if (current) {
                            params.set(baseKey, current + ',' + value);
                        } else {
                            params.set(baseKey, value);
                        }
                    } else {
                        params.set(key, value);
                    }
                }
            }

            // Exclude helper/submit keys
            params.delete('brz_filter_submit');

            // Merge with static params (like category ID, orderby) if they exist
            const currentParams = new URLSearchParams(window.location.search);
            currentParams.forEach((val, name) => {
                // If it's not a spec parameter, preserve it
                const isSpec = Array.from(form.elements).some(el => el.name === name || el.name === name + '[]' || name.startsWith(el.name.replace('[]','')));
                if (!isSpec && name !== 'paged') {
                    params.set(name, val);
                }
            });

            const queryStr = params.toString();
            fetchUrl = window.location.pathname + (queryStr ? '?' + queryStr : '');
        }

        // 3. Perform Fetch
        fetch(fetchUrl)
            .then(response => {
                if (!response.ok) throw new Error('HTTP error ' + response.status);
                return response.text();
            })
            .then(html => {
                const parser = new DOMParser();
                const doc = parser.parseFromString(html, 'text/html');

                // Extract and replace products loop
                const newProducts = doc.querySelector(config.container_selector);
                const currentProducts = document.querySelector(config.container_selector);
                if (newProducts && currentProducts) {
                    currentProducts.innerHTML = newProducts.innerHTML;
                }

                // Extract and replace product count text
                const newCount = doc.querySelector(config.count_selector);
                const currentCount = document.querySelector(config.count_selector);
                if (newCount && currentCount) {
                    currentCount.innerHTML = newCount.innerHTML;
                }

                // Sync sidebar form fields from URL (essential for popstate or manual navigation)
                syncFormFieldsFromUrl(fetchUrl, form);

                // Update Browser URL & State
                if (config.push_state && updateHistory) {
                    window.history.pushState({ path: fetchUrl }, '', fetchUrl);
                }

                // Re-bind pagination click events (since the container HTML changed)
                bindPaginationLinks(config);

                // Hide spinner
                overlay.classList.remove('active');

                // Dispatch Custom Event for theme scripts to bind layout quickviews/etc.
                document.body.dispatchEvent(new CustomEvent('brz_filters_updated', { detail: { url: fetchUrl } }));

                // Compatibility: If theme has mobile filter close trigger, trigger it
                const closeBtn = document.querySelector('.close_filter_panels');
                if (closeBtn && window.getComputedStyle(closeBtn).display !== 'none') {
                    // If mobile sidebar open button exists, close it
                    closeBtn.click();
                }
            })
            .catch(error => {
                console.error('Filtering failed: ', error);
                overlay.classList.remove('active');
            });
    }
}

/**
 * Synchronize sidebar form values with a given URL (e.g. on popstate).
 */
function syncFormFieldsFromUrl(urlStr, form) {
    const url = new URL(urlStr, window.location.origin);
    const params = new URLSearchParams(url.search);

    // Reset checkboxes and text inputs
    Array.from(form.elements).forEach(function(el) {
        if (!el.name) return;
        const name = el.name.replace('[]', '');

        if (el.type === 'checkbox') {
            const urlVal = params.get(name);
            if (urlVal) {
                const vals = urlVal.split(',');
                el.checked = vals.includes(el.value);
            } else {
                el.checked = false;
            }
        } else if (el.type === 'range' || el.type === 'number') {
            const urlVal = params.get(el.name);
            if (urlVal !== null) {
                el.value = urlVal;
            } else {
                // reset to default bounds
                if (el.classList.contains('brz-range-input-min') || el.name.endsWith('_min')) {
                    el.value = el.min || 0;
                } else if (el.classList.contains('brz-range-input-max') || el.name.endsWith('_max')) {
                    el.value = el.max || 100;
                } else {
                    el.value = '';
                }
            }
        } else if (el.type !== 'hidden') {
            el.value = params.get(name) || '';
        }
    });

    // Re-initialize slider tracks display
    const rangeWrappers = form.querySelectorAll('.brz-range-slider-wrapper');
    rangeWrappers.forEach(function(wrapper) {
        initDualRangeSlider(wrapper);
    });
}
