/**
Custom Service Calculator - Master Registration JS
Version: 9.3 (финальная: all-in-one, все правки собраны)
*/
(function() {
'use strict';

const CONFIG = window.CSC_REGISTRATION_CONFIG || {};

// i18n: доступ к серверному словарю переводов (window.CSC_I18N, формируется
// в PHP csc_js_strings_registration()). Фолбэк — оригинальная русская строка.
window.cscI18n = window.cscI18n || {
    t: function (key, fallback) {
        var d = (typeof CSC_I18N !== 'undefined' && CSC_I18N) ? CSC_I18N : {};
        return (d[key] !== undefined && d[key] !== null) ? d[key] : (fallback !== undefined ? fallback : key);
    }
};
const __c = window.cscI18n.t;

let currentStep = 1;
let registrationMap = null;
let registrationMarker = null;
let registrationCircle = null;
let mapInitialized = false;
let errorHideTimeout = null;

// ============================================================
// НАВИГАЦИЯ ПО ШАГАМ
// ============================================================
window.cscRegistrationNextStep = function(step) {
    if (!validateStep(step)) return;
    
    document.querySelector('.csc-form-step[data-step="' + step + '"]').classList.remove('active');
    document.querySelector('.csc-form-step[data-step="' + (step + 1) + '"]').classList.add('active');
    document.querySelector('.csc-progress-step[data-step="' + step + '"]').classList.remove('active');
    document.querySelector('.csc-progress-step[data-step="' + step + '"]').classList.add('completed');
    document.querySelector('.csc-progress-step[data-step="' + (step + 1) + '"]').classList.add('active');
    currentStep = step + 1;
    
    window.scrollTo({ top: 0, behavior: 'smooth' });

    if (currentStep === 2) {
        setTimeout(() => {
            if (registrationMap) {
                registrationMap.invalidateSize();
            } else {
                initRegistrationMap();
            }
        }, 600);
    }
};

window.cscRegistrationPrevStep = function(step) {
    document.querySelector('.csc-form-step[data-step="' + step + '"]').classList.remove('active');
    document.querySelector('.csc-form-step[data-step="' + (step - 1) + '"]').classList.add('active');
    document.querySelector('.csc-progress-step[data-step="' + step + '"]').classList.remove('active');
    document.querySelector('.csc-progress-step[data-step="' + (step - 1) + '"]').classList.remove('completed');
    document.querySelector('.csc-progress-step[data-step="' + (step - 1) + '"]').classList.add('active');
    currentStep = step - 1;

    window.scrollTo({ top: 0, behavior: 'smooth' });

    if (currentStep === 2 && registrationMap) {
        setTimeout(() => {
            registrationMap.invalidateSize();
        }, 600);
    }
};

// ============================================================
// ОШИБКИ ВАЛИДАЦИИ
// ============================================================
function showValidationError(message) {
    const errorEl = document.getElementById('csc-registration-error');
    const errorMsg = document.getElementById('csc-error-message');
    
    if (errorEl && errorMsg) {
        if (errorHideTimeout) clearTimeout(errorHideTimeout);
        
        errorMsg.textContent = message;
        errorEl.style.setProperty('display', 'block', 'important');
        errorEl.scrollIntoView({ behavior: 'smooth', block: 'center' });
        
        errorHideTimeout = setTimeout(() => {
            if (errorEl) errorEl.style.setProperty('display', 'none', 'important');
        }, 6000);
    }
}

// ============================================================
// ВАЛИДАЦИЯ ШАГОВ
// ============================================================
function validateStep(step) {
    // === ШАГ 1 ===
    if (step === 1) {
        const agreement = document.getElementById('csc-accept-agreement');
        const city = document.getElementById('csc-reg-city');
        if (!agreement || !agreement.checked) {
            showValidationError(__c('agree_required', 'Необходимо принять условия соглашения'));
            return false;
        }
        if (!city || !city.value) {
            showValidationError(__c('select_city', 'Выберите город'));
            return false;
        }
        return true;
    }

    // === ШАГ 2 ===
    if (step === 2) {
        const name = document.getElementById('csc-reg-name');
        const phone = document.getElementById('csc-reg-phone');
        const email = document.getElementById('csc-reg-email');
        const specializations = document.getElementById('csc-reg-specializations');

        if (!name || !name.value.trim()) {
            showValidationError(__c('name_required', 'Укажите ФИО'));
            if (name) name.focus();
            return false;
        }
        if (!phone || !phone.value.trim()) {
            showValidationError(__c('phone_required', 'Укажите телефон'));
            if (phone) phone.focus();
            return false;
        }
        
        const isEmailValid = email && email.value.trim() && 
                         (typeof email.checkValidity !== 'function' || email.checkValidity());
        if (!email || !isEmailValid) {
            showValidationError(__c('email_invalid', 'Укажите корректный email'));
            if (email) email.focus();
            return false;
        }
        
        if (!specializations || specializations.selectedOptions.length === 0) {
            showValidationError(__c('spec_required', 'Выберите хотя бы одну специализацию'));
            return false;
        }

        const latInput = document.getElementById('csc-reg-lat');
        const lngInput = document.getElementById('csc-reg-lng');
        const addressInput = document.getElementById('csc-reg-service-address');

        const lat = parseFloat(latInput ? latInput.value : '');
        const lng = parseFloat(lngInput ? lngInput.value : '');

        if (!addressInput || !addressInput.value.trim()) {
            showValidationError(__c('address_required', 'Укажите адрес проживания / точку выезда'));
            if (addressInput) addressInput.focus();
            return false;
        }
        if (isNaN(lat) || isNaN(lng) || (lat === 0 && lng === 0)) {
            showValidationError(__c('map_point_required', 'Укажите точку выезда на карте. Нажмите «Найти на карте» или поставьте маркер вручную.'));
            return false;
        }

        return true;
    }

    // === ШАГ 3 ===
    if (step === 3) {
        const docInput = document.getElementById('csc-reg-document');
        if (!docInput || !docInput.files || !docInput.files.length) {
            showValidationError(__c('doc_required', 'Загрузите документ'));
            return false;
        }
        return true;
    }

    // === ШАГ 4: Расписание, минимум 4 часа ===
    if (step === 4) {
        const selectedDays = document.querySelectorAll('.csc-schedule-day-toggle:checked');
        
        if (selectedDays.length === 0) {
            showValidationError(__c('day_required', 'Выберите хотя бы один рабочий день'));
            return false;
        }
        
        const dayNames = {
            monday: __c('monday', 'Понедельник'),
            tuesday: __c('tuesday', 'Вторник'),
            wednesday: __c('wednesday', 'Среда'),
            thursday: __c('thursday', 'Четверг'),
            friday: __c('friday', 'Пятница'),
            saturday: __c('saturday', 'Суббота'),
            sunday: __c('sunday', 'Воскресенье')
        };
        
        for (let i = 0; i < selectedDays.length; i++) {
            const checkbox = selectedDays[i];
            
            // Ключ дня из name чекбокса: schedule[monday][enabled] → monday
            const nameAttr = checkbox.getAttribute('name') || '';
            const nameMatch = nameAttr.match(/schedule\[([a-z]+)\]/i);
            const dayKey = nameMatch ? nameMatch[1] : null;
            
            if (!dayKey) {
                showValidationError(__c('day_unknown', 'Не удалось определить день недели в расписании'));
                return false;
            }
            
            const dayRow = checkbox.closest('.csc-schedule-day');
            const fromInput = dayRow ? dayRow.querySelector('input[name="schedule[' + dayKey + '][from]"]') : null;
            const toInput = dayRow ? dayRow.querySelector('input[name="schedule[' + dayKey + '][to]"]') : null;
            
            if (!fromInput || !toInput || !fromInput.value || !toInput.value) {
                showValidationError(__c('day_time_required', 'Укажите время работы в день:') + ' ' + (dayNames[dayKey] || dayKey));
                return false;
            }
            
            const fromParts = fromInput.value.split(':');
            const toParts = toInput.value.split(':');
            const fromMinutes = parseInt(fromParts[0], 10) * 60 + parseInt(fromParts[1], 10);
            const toMinutes = parseInt(toParts[0], 10) * 60 + parseInt(toParts[1], 10);
            const duration = toMinutes - fromMinutes;
            
            if (duration <= 0) {
                showValidationError((dayNames[dayKey] || dayKey) + ': ' + __c('day_time_order', 'время окончания должно быть позже времени начала'));
                return false;
            }
            
            if (duration < 240) {
                const hours = Math.floor(duration / 60);
                const minutes = duration % 60;
                showValidationError((dayNames[dayKey] || dayKey) + ': ' + __c('day_min_hours', 'минимальная продолжительность рабочего дня — 4 часа. Сейчас:') + ' ' + hours + ' ' + __c('hours_short', 'ч') + ' ' + minutes + ' ' + __c('minutes_short', 'мин'));
                return false;
            }
        }
        
        return true;
    }

    return true;
}

// ============================================================
// ПРЕВЬЮ ФАЙЛОВ
// ============================================================
function initFileUploads() {
    const photoInput = document.getElementById('csc-reg-photo');
    const docInput = document.getElementById('csc-reg-document');

    if (photoInput) {
        photoInput.addEventListener('change', function() {
            const preview = document.getElementById('csc-photo-preview');
            if (!preview) return;
            preview.innerHTML = '';
            if (this.files.length) {
                const reader = new FileReader();
                reader.onload = (e) => {
                    preview.innerHTML = '<img src="' + e.target.result + '" alt="' + __c('alt_photo', 'Фото') + '" style="max-width:200px;max-height:200px;border-radius:8px;">';
                };
                reader.readAsDataURL(this.files[0]);
            }
        });
    }

    if (docInput) {
        docInput.addEventListener('change', function() {
            const preview = document.getElementById('csc-document-preview');
            if (!preview) return;
            preview.innerHTML = '';
            if (this.files.length) {
                const reader = new FileReader();
                reader.onload = (e) => {
                    preview.innerHTML = '<img src="' + e.target.result + '" alt="' + __c('alt_doc', 'Документ') + '" style="max-width:200px;max-height:200px;border-radius:8px;">';
                };
                reader.readAsDataURL(this.files[0]);
            }
        });
    }
}

// ============================================================
// РАСПИСАНИЕ
// ============================================================
function initScheduleToggles() {
    document.querySelectorAll('.csc-schedule-day-toggle').forEach(toggle => {
        toggle.addEventListener('change', function() {
            const timesEl = this.closest('.csc-schedule-day').querySelector('.csc-schedule-times');
            if (timesEl) {
                if (this.checked) {
                    timesEl.style.setProperty('display', 'flex', 'important');
                } else {
                    timesEl.style.setProperty('display', 'none', 'important');
                }
            }
        });
    });
}

// ============================================================
// ОТПРАВКА ФОРМЫ
// ============================================================
function initFormSubmit() {
    const form = document.getElementById('csc-registration-form');
    if (!form) return;

    form.addEventListener('submit', async function(e) {
        e.preventDefault();
        const submitBtn = document.getElementById('csc-submit-registration');
        
        // ВАЛИДАЦИЯ ШАГА 4 ПЕРЕД ОТПРАВКОЙ
        if (!validateStep(4)) {
            return;
        }
        
        if (submitBtn) {
            submitBtn.disabled = true;
            submitBtn.innerHTML = '<span class="csc-spinner"></span> ' + __c('sending', 'Отправка...');
        }

        const formData = new FormData(form);
        const restUrl = CONFIG.restUrl || (window.location.origin + '/wp-json/csc/v1/');

        try {
            const response = await fetch(restUrl + 'register-master', {
                method: 'POST',
                body: formData,
            });

            if (!response.ok) {
                throw new Error(__c('server_error', 'Ошибка сервера:') + ' ' + response.status);
            }

            const data = await response.json();

            if (data.success) {
                form.style.setProperty('display', 'none', 'important');
                
                const progressEl = document.querySelector('.csc-registration-progress');
                if (progressEl) progressEl.style.setProperty('display', 'none', 'important');
                
                const completenessEl = document.getElementById('csc-profile-completeness');
                if (completenessEl) completenessEl.style.setProperty('display', 'none', 'important');
                
                const successEl = document.getElementById('csc-registration-success');
                if (successEl) {
                    successEl.style.setProperty('display', 'block', 'important');
                    setTimeout(() => {
                        window.scrollTo({ top: 0, behavior: 'smooth' });
                    }, 100);
                }
            } else {
                showValidationError(data.message || __c('submit_error', 'Ошибка при отправке'));
                if (submitBtn) {
                    submitBtn.disabled = false;
                    submitBtn.innerHTML = __c('submit', 'Отправить заявку') + ' ✓';
                }
            }
        } catch (error) {
            console.error('[CSC_REG] Submit error:', error);
            showValidationError(__c('network_error2', 'Ошибка сети. Проверьте подключение.'));
            if (submitBtn) {
                submitBtn.disabled = false;
                submitBtn.innerHTML = __c('submit', 'Отправить заявку') + ' ✓';
            }
        }
    });
}

// ============================================================
// МАСКА ТЕЛЕФОНА
// ============================================================
function initPhoneMask() {
    const phoneInput = document.getElementById('csc-reg-phone');
    if (!phoneInput) return;

    phoneInput.addEventListener('input', function(e) {
        let value = e.target.value.replace(/\D/g, '');
        if (value.length > 0) {
            if (value[0] === '7' || value[0] === '8') {
                value = value.substring(1);
            }
            let formatted = '+7';
            if (value.length > 0) formatted += ' (' + value.substring(0, 3);
            if (value.length >= 3) formatted += ') ' + value.substring(3, 6);
            if (value.length >= 6) formatted += '-' + value.substring(6, 8);
            if (value.length >= 8) formatted += '-' + value.substring(8, 10);
            e.target.value = formatted;
        }
    });
}

// ============================================================
// КАРТА
// ============================================================
function loadLeafletDynamically(callback) {
    if (typeof L !== 'undefined') {
        callback();
        return;
    }
    
    if (!document.getElementById('leaflet-css-dynamic')) {
        const css = document.createElement('link');
        css.id = 'leaflet-css-dynamic';
        css.rel = 'stylesheet';
        css.href = 'https://unpkg.com/leaflet@1.9.4/dist/leaflet.css';
        document.head.appendChild(css);
    }
    
    const script = document.createElement('script');
    script.src = 'https://unpkg.com/leaflet@1.9.4/dist/leaflet.js';
    script.onload = function() { callback(); };
    document.head.appendChild(script);
}

function initRegistrationMap() {
    const mapEl = document.getElementById('csc-reg-map');
    if (!mapEl) return;
    
    if (mapInitialized) {
        if (registrationMap) registrationMap.invalidateSize();
        return;
    }
    
    const rect = mapEl.getBoundingClientRect();
    if (rect.height === 0 || rect.width === 0) {
        setTimeout(initRegistrationMap, 100);
        return;
    }
    
    if (typeof L === 'undefined') {
        loadLeafletDynamically(function() { createMap(); });
        return;
    }
    
    createMap();
}

function createMap() {
    const mapEl = document.getElementById('csc-reg-map');
    if (!mapEl || mapInitialized) return;
    if (typeof L === 'undefined') return;

    L.Icon.Default.prototype.options.imagePath = 'https://unpkg.com/leaflet@1.9.4/dist/images/';

    const center = Array.isArray(CONFIG.mapCenter) && CONFIG.mapCenter.length === 2
        ? CONFIG.mapCenter
        : [55.7558, 37.6173];

    try {
        registrationMap = L.map('csc-reg-map', {
            center: center,
            zoom: 12,
            scrollWheelZoom: true,
            maxBoundsViscosity: 1.0
        });

        const restUrl = CONFIG.restUrl || (window.location.origin + '/wp-json/csc/v1/');
        
        const tileLayer = L.tileLayer(restUrl + 'map-tile/{z}/{x}/{y}', {
            attribution: '&copy; OpenStreetMap contributors',
            maxZoom: 19,
            minZoom: 2
        });

        let fallbackUsed = false;
        tileLayer.on('tileerror', function() {
            if (!fallbackUsed) {
                fallbackUsed = true;
                tileLayer.setUrl('https://server.arcgisonline.com/ArcGIS/rest/services/World_Street_Map/MapServer/tile/{z}/{y}/{x}');
            }
        });

        tileLayer.addTo(registrationMap);

        const customIcon = L.icon({
            iconUrl: 'https://unpkg.com/leaflet@1.9.4/dist/images/marker-icon.png',
            iconRetinaUrl: 'https://unpkg.com/leaflet@1.9.4/dist/images/marker-icon-2x.png',
            shadowUrl: 'https://unpkg.com/leaflet@1.9.4/dist/images/marker-shadow.png',
            iconSize: [25, 41],
            iconAnchor: [12, 41],
            popupAnchor: [1, -34],
            shadowSize: [41, 41]
        });

        const radiusInput = document.getElementById('csc-reg-radius');
        const initialRadius = radiusInput ? parseInt(radiusInput.value, 10) : (CONFIG.radiusDefault || 10);

        registrationMarker = L.marker(center, {
            draggable: true,
            title: __c('departure_point', 'Точка выезда'),
            icon: customIcon
        }).addTo(registrationMap);

        registrationCircle = L.circle(center, {
            radius: initialRadius * 1000,
            color: '#6366f1',
            weight: 2,
            fillColor: '#6366f1',
            fillOpacity: 0.12,
        }).addTo(registrationMap);

        registrationMarker.on('dragend', function(e) {
            const pos = e.target.getLatLng();
            setServiceCoordinates(pos.lat, pos.lng);
            registrationCircle.setLatLng(pos);
            registrationMap.setView(pos);
            updateAddressFromPoint(pos.lat, pos.lng);
        });

        registrationMap.on('click', function(e) {
            registrationMarker.setLatLng(e.latlng);
            setServiceCoordinates(e.latlng.lat, e.latlng.lng);
            registrationCircle.setLatLng(e.latlng);
            updateAddressFromPoint(e.latlng.lat, e.latlng.lng);
            
            setTimeout(() => {
                registrationMap.invalidateSize();
            }, 50);
        });

        mapInitialized = true;
        
        setTimeout(() => { if (registrationMap) registrationMap.invalidateSize(); }, 100);
        setTimeout(() => { if (registrationMap) registrationMap.invalidateSize(); }, 300);
        setTimeout(() => { if (registrationMap) registrationMap.invalidateSize(); }, 600);
        setTimeout(() => { if (registrationMap) registrationMap.invalidateSize(); }, 1000);
        
    } catch (err) {
        console.error('[CSC_REG] Map creation error:', err);
        mapInitialized = false;
    }
}

function setServiceCoordinates(lat, lng) {
    const latInput = document.getElementById('csc-reg-lat');
    const lngInput = document.getElementById('csc-reg-lng');
    if (latInput) latInput.value = lat.toFixed(7);
    if (lngInput) lngInput.value = lng.toFixed(7);
	scheduleZoneCheck();
}

// ============================================================
// АВТОЗАПОЛНЕНИЕ АДРЕСА
// ============================================================
function updateAddressFromPoint(lat, lng) {
    const addressInput = document.getElementById('csc-reg-service-address');
    if (!addressInput) return;
    
    const coordsText = lat.toFixed(6) + ', ' + lng.toFixed(6);
    addressInput.value = coordsText;
    
    const restUrl = CONFIG.restUrl || (window.location.origin + '/wp-json/csc/v1/');
    
    fetch(restUrl + 'reverse-geocode', {
        method: 'POST',
        headers: {'Content-Type': 'application/json'},
        body: JSON.stringify({lat: lat, lng: lng})
    })
    .then(r => r.json())
    .then(data => {
        if (data.success && data.address && addressInput.value === coordsText) {
            addressInput.value = data.address;
        }
    })
    .catch(err => {
        console.warn('[CSC_REG] Reverse geocode failed:', err);
    });
}

// ============================================================
// LIVE-ПРОВЕРКА ЗАГРУЗКИ ЗОНЫ
// ============================================================
let zoneCheckTimeout = null;

function scheduleZoneCheck() {
    if (zoneCheckTimeout) clearTimeout(zoneCheckTimeout);
    zoneCheckTimeout = setTimeout(checkZoneCapacity, 800);
}

function checkZoneCapacity() {
    const warnEl = document.getElementById('csc-zone-warning');
    const latInput = document.getElementById('csc-reg-lat');
    const lngInput = document.getElementById('csc-reg-lng');
    const citySel = document.getElementById('csc-reg-city');
    const radiusInput = document.getElementById('csc-reg-radius');
    if (!warnEl || !latInput || !lngInput) return;
    
    const lat = parseFloat(latInput.value);
    const lng = parseFloat(lngInput.value);
    if (isNaN(lat) || isNaN(lng) || (lat === 0 && lng === 0)) {
        warnEl.style.display = 'none';
        return;
    }
    
    const restUrl = CONFIG.restUrl || (window.location.origin + '/wp-json/csc/v1/');
    const fd = new FormData();
    fd.append('city', citySel ? citySel.value : CONFIG.currentCity);
    fd.append('lat', lat);
    fd.append('lng', lng);
    fd.append('radius', radiusInput ? radiusInput.value : 10);
    
    fetch(restUrl + 'zone-check', { method: 'POST', body: fd })
        .then(r => r.json())
        .then(data => {
            if (!data.success || !data.data || !data.data.limit) {
                warnEl.style.display = 'none';
                return;
            }
            const d = data.data;
            warnEl.style.display = 'block';
            if (d.ok) {
                warnEl.style.background = '#f0fdf4';
                warnEl.style.borderColor = '#bbf7d0';
                warnEl.style.color = '#166534';
                warnEl.textContent = __c('zone_free', 'Зона свободна: занято %1$s из %2$s мест.').replace('%1$s', d.max_current).replace('%2$s', d.limit);
            } else {
                warnEl.style.background = '#fffbeb';
                warnEl.style.borderColor = '#fde68a';
                warnEl.style.color = '#92400e';
                warnEl.textContent = __c('zone_full', '\u26a0 В этой зоне уже %1$s мастеров при лимите %2$s. Заявка будет отклонена \u2014 выберите другую точку выезда или уменьшите радиус.').replace('%1$s', d.max_current).replace('%2$s', d.limit);
            }
        })
        .catch(() => { warnEl.style.display = 'none'; });
}
// ============================================================
// ГЕОКОДИНГ ПО АДРЕСУ
// ============================================================
function initGeocodeButton() {
    const btn = document.getElementById('csc-geocode-address-btn');
    const addressInput = document.getElementById('csc-reg-service-address');
    if (!btn || !addressInput) return;

    btn.addEventListener('click', async function() {
        const address = addressInput.value.trim();
        if (!address) {
            showValidationError(__c('address_for_search', 'Введите адрес для поиска на карте'));
            addressInput.focus();
            return;
        }

        const originalHtml = btn.innerHTML;
        btn.disabled = true;
        btn.innerHTML = '<span class="csc-spinner"></span> ' + __c('searching', 'Поиск...');

        const restUrl = CONFIG.restUrl || (window.location.origin + '/wp-json/csc/v1/');

        try {
            const formData = new FormData();
            formData.append('address', address);

            const response = await fetch(restUrl + 'geocode-address', {
                method: 'POST',
                body: formData,
            });

            if (!response.ok) throw new Error(__c('server_error', 'Ошибка сервера:') + ' ' + response.status);

            const data = await response.json();

            if (data.success && data.data && typeof data.data.lat === 'number') {
                const lat = data.data.lat;
                const lng = data.data.lng;
                const display_name = data.data.display_name;

                const waitForMap = (callback, attempts) => {
                    attempts = attempts || 0;
                    if (mapInitialized && registrationMap && registrationMarker) {
                        callback();
                        return;
                    }
                    if (attempts > 30) {
                        showValidationError(__c('map_not_ready', 'Карта не готова. Поставьте точку вручную.'));
                        return;
                    }
                    setTimeout(() => waitForMap(callback, attempts + 1), 100);
                };

                if (!mapInitialized) initRegistrationMap();

                waitForMap(() => {
                    const latlng = L.latLng(lat, lng);
                    registrationMarker.setLatLng(latlng);
                    setServiceCoordinates(lat, lng);
                    registrationCircle.setLatLng(latlng);
                    registrationMap.setView(latlng, 14);
                    
                    setTimeout(() => {
                        registrationMap.invalidateSize();
                    }, 100);

                    if (display_name) {
                        addressInput.value = display_name;
                    }
                });
            } else {
                showValidationError((data.message || __c('address_not_found', 'Адрес не найден')) + __c('manual_point_hint', '. Поставьте точку на карте вручную.'));
            }
        } catch (e) {
            console.error('[CSC_REG] Geocode error:', e);
            showValidationError(__c('geocode_error', 'Ошибка геокодирования.'));
        } finally {
            btn.disabled = false;
            btn.innerHTML = originalHtml;
        }
    });
}

// ============================================================
// ПОЛЗУНОК РАДИУСА
// ============================================================
function initRadiusSlider() {
    const slider = document.getElementById('csc-reg-radius');
    const valueLabel = document.getElementById('csc-reg-radius-value');
    if (!slider) return;

    const min = CONFIG.radiusMin || 3;
    const max = CONFIG.radiusMax || 20;
    slider.min = String(min);
    slider.max = String(max);

    const updateRadius = function() {
        const km = parseInt(slider.value, 10);
        if (valueLabel) valueLabel.textContent = km;
        if (registrationCircle) {
            registrationCircle.setRadius(km * 1000);
		scheduleZoneCheck();
        }
    };

    slider.addEventListener('input', updateRadius);
    updateRadius();
}

// ============================================================
// ИНИЦИАЛИЗАЦИЯ
// ============================================================
function init() {
    initFileUploads();
    initScheduleToggles();
    initFormSubmit();
    initPhoneMask();
    initGeocodeButton();
    initRadiusSlider();
	const citySel = document.getElementById('csc-reg-city');
    if (citySel) citySel.addEventListener('change', scheduleZoneCheck);
    
    setTimeout(() => {
        updateProgressIndicator();
    }, 100);
    
    const form = document.getElementById('csc-registration-form');
    if (form) {
        form.addEventListener('input', debounce(updateProgressIndicator, 300));
        form.addEventListener('change', debounce(updateProgressIndicator, 300));
    }
}

// ============================================================
// ПРОГРЕСС-ИНДИКАТОР
// ============================================================
function updateProgressIndicator() {
    const progressEl = document.getElementById('csc-profile-completeness');
    const progressFill = document.getElementById('csc-completeness-fill');
    const progressText = document.getElementById('csc-completeness-text');
    
    if (!progressEl || !progressFill || !progressText) return;
    
    let totalFields = 0;
    let filledFields = 0;
    
    const agreement = document.getElementById('csc-accept-agreement');
    const city = document.getElementById('csc-reg-city');
    totalFields += 2;
    if (agreement && agreement.checked) filledFields++;
    if (city && city.value) filledFields++;
    
    const name = document.getElementById('csc-reg-name');
    const phone = document.getElementById('csc-reg-phone');
    const email = document.getElementById('csc-reg-email');
    const experience = document.getElementById('csc-reg-experience');
    const specializations = document.getElementById('csc-reg-specializations');
    const address = document.getElementById('csc-reg-service-address');
    const lat = document.getElementById('csc-reg-lat');
    const lng = document.getElementById('csc-reg-lng');
    const about = document.getElementById('csc-reg-about');
    const photo = document.getElementById('csc-reg-photo');
    
    totalFields += 10;
    if (name && name.value.trim()) filledFields++;
    if (phone && phone.value.trim()) filledFields++;
    if (email && email.value.trim() && (typeof email.checkValidity !== 'function' || email.checkValidity())) filledFields++;
    if (experience && experience.value && experience.value !== '0') filledFields++;
    if (specializations && specializations.selectedOptions.length > 0) filledFields++;
    if (address && address.value.trim()) filledFields++;
    if (lat && lat.value && lat.value !== '' && !isNaN(parseFloat(lat.value))) filledFields++;
    if (lng && lng.value && lng.value !== '' && !isNaN(parseFloat(lng.value))) filledFields++;
    if (about && about.value.trim()) filledFields++;
    if (photo && photo.files && photo.files.length > 0) filledFields++;
    
    const documentPhoto = document.getElementById('csc-reg-document');
    totalFields += 1;
    if (documentPhoto && documentPhoto.files && documentPhoto.files.length > 0) filledFields++;
    
    const scheduleDays = document.querySelectorAll('.csc-schedule-day-toggle:checked');
    totalFields += 1;
    if (scheduleDays.length > 0) filledFields++;
    
    const percentage = Math.round((filledFields / totalFields) * 100);
    
    progressFill.style.width = percentage + '%';
    progressText.textContent = percentage + '%';
    
    if (percentage >= 100) {
        progressFill.style.background = 'linear-gradient(90deg, #10b981, #059669)';
    } else if (percentage >= 70) {
        progressFill.style.background = 'linear-gradient(90deg, #f59e0b, #d97706)';
    } else {
        progressFill.style.background = 'linear-gradient(90deg, #6366f1, #4f46e5)';
    }
}

function debounce(func, wait) {
    let timeout;
    return function executedFunction(...args) {
        const later = () => {
            clearTimeout(timeout);
            func(...args);
        };
        clearTimeout(timeout);
        timeout = setTimeout(later, wait);
    };
}

if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', init);
} else {
    init();
}

})();