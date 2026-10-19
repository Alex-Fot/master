<?php
if (!defined('ABSPATH')) exit;

// =============== ШОРТКОД КАЛЬКУЛЯТОРА ===============
function csc_calculator_shortcode() {
    $s = get_option('csc_main_settings', []);
    $enable_sbp = !empty($s['enable_sbp']);
    $min_order_amount = intval($s['min_order_amount'] ?? 0);
    $promo_disabled = get_option('csc_promo_disable_global', 0);
    $is_promo_active = !$promo_disabled;

    // ============================================================
    // ИСПРАВЛЕНО: Динамический расчёт минимальной даты
    // Если текущее время >= конца рабочего дня (с запасом на дорогу),
    // минимальная дата = завтра. Иначе — сегодня.
    // ============================================================
    $work_end_h = intval($s['work_hours_end'] ?? 18);
    $travel_time_calc = intval($s['travel_time'] ?? 0);
    $min_date = wp_date('Y-m-d');
    $current_hour_decimal = (int)wp_date('H') + ((int)wp_date('i') / 60);

    if ($current_hour_decimal >= ($work_end_h - ($travel_time_calc / 60))) {
        $min_date = wp_date('Y-m-d', strtotime('+1 day'));
    }

    // ============================================================
    // КОНЕЦ РАСЧЁТА МИНИМАЛЬНОЙ ДАТЫ
    // ============================================================

    ob_start();
    ?>

<script src="https://cdnjs.cloudflare.com/ajax/libs/html2canvas/1.4.1/html2canvas.min.js"></script>
<style>
:root {
    --csc-primary: #3b82f6;
    --csc-primary-dark: #2563eb;
    --csc-success: #10b981;
    --csc-danger: #ef4444;
}

#csc-calculator {
    --csc-bg: rgba(248,250,252,0.4);
    --csc-card: rgba(255,255,255,0.5);
    --csc-text: #1e293b;
    --csc-border: rgba(255,255,255,0.6);
    --csc-shadow: 0 8px 32px rgba(0,0,0,0.08), inset 0 1px 0 rgba(255,255,255,0.9);
    --csc-input-bg: rgba(255,255,255,0.6);
    font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Helvetica, Arial, sans-serif;
    background: var(--csc-bg);
    color: var(--csc-text);
    padding: 16px 16px 100px 16px;
    max-width: 800px;
    margin: 0 auto;
    border-radius: 24px;
    transition: background 0.3s ease, color 0.3s ease;
    position: relative;
    border: 1px solid var(--csc-border);
}

@supports (backdrop-filter: blur(12px)) or (-webkit-backdrop-filter: blur(12px)) {
    #csc-calculator {
        backdrop-filter: blur(20px) saturate(180%);
        -webkit-backdrop-filter: blur(20px) saturate(180%);
    }
}

#csc-calculator[data-theme="dark"] {
    --csc-bg: rgba(15,23,42,0.5);
    --csc-card: rgba(30,41,59,0.5);
    --csc-text: #f1f5f9;
    --csc-border: rgba(255,255,255,0.1);
    --csc-shadow: 0 8px 32px rgba(0,0,0,0.3), inset 0 1px 0 rgba(255,255,255,0.15);
    --csc-input-bg: rgba(15,23,42,0.6);
}

/* === ДОБАВЛЕНО ИЗ ВЕРСИИ 2: CSS-переменные контролов === */
#csc-calculator {
    color-scheme: light;
    --csc-ctl-bg: #ffffff;
    --csc-ctl-fg: #0f172a;
    --csc-ctl-muted: #64748b;
    --csc-ctl-br: rgba(15, 23, 42, 0.14);
    --csc-ctl-br-hover: rgba(59, 130, 246, 0.55);
    --csc-ctl-ring: rgba(59, 130, 246, 0.18);
    --csc-ctl-shadow: 0 4px 14px rgba(15, 23, 42, 0.08);
    --csc-ctl-arrow: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 24 24' fill='none' stroke='%230f172a' stroke-width='2.2' stroke-linecap='round' stroke-linejoin='round'%3E%3Cpath d='M6 9l6 6 6-6'/%3E%3C/svg%3E");
}

#csc-calculator[data-theme="dark"] {
    color-scheme: dark;
    --csc-ctl-bg: #0b1220;
    --csc-ctl-fg: #f8fafc;
    --csc-ctl-muted: #94a3b8;
    --csc-ctl-br: rgba(255, 255, 255, 0.16);
    --csc-ctl-br-hover: rgba(96, 165, 250, 0.65);
    --csc-ctl-ring: rgba(96, 165, 250, 0.22);
    --csc-ctl-shadow: 0 4px 16px rgba(0, 0, 0, 0.35);
    --csc-ctl-arrow: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 24 24' fill='none' stroke='%23f8fafc' stroke-width='2.2' stroke-linecap='round' stroke-linejoin='round'%3E%3Cpath d='M6 9l6 6 6-6'/%3E%3C/svg%3E");
}

.csc-theme-toggle {
    position: fixed;
    bottom: 30px;
    right: 30px;
    width: 56px;
    height: 56px;
    border-radius: 50%;
    background: var(--csc-card);
    border: 1px solid rgba(255,255,255,0.5);
    color: var(--csc-text);
    font-size: 26px;
    display: flex;
    align-items: center;
    justify-content: center;
    cursor: pointer;
    z-index: 99999;
    opacity: 0.8;
    transition: all 0.3s ease;
    box-shadow: 0 4px 12px rgba(0,0,0,0.1), inset 0 1px 0 rgba(255,255,255,0.8);
    padding: 0;
    outline: none;
    line-height: 1;
    will-change: transform;
}

@supports (backdrop-filter: blur(10px)) or (-webkit-backdrop-filter: blur(10px)) {
    .csc-theme-toggle {
        backdrop-filter: blur(15px) saturate(180%);
        -webkit-backdrop-filter: blur(15px) saturate(180%);
    }
}

.csc-theme-toggle:hover {
    opacity: 1;
    transform: scale(1.1);
    border-color: var(--csc-primary);
    box-shadow: 0 6px 16px rgba(59, 130, 246, 0.3), inset 0 1px 0 rgba(255,255,255,0.8);
}

@media (max-width: 768px) {
    .csc-theme-toggle {
        width: 48px;
        height: 48px;
        font-size: 22px;
        bottom: 20px;
        right: 20px;
    }
}

.csc-toast {
    position: fixed;
    top: 20px;
    left: 50%;
    transform: translateX(-50%);
    background: var(--csc-primary);
    color: white;
    padding: 14px 24px;
    border-radius: 12px;
    z-index: 100000;
    display: none;
    box-shadow: 0 8px 32px rgba(59,130,246,0.3), inset 0 1px 0 rgba(255,255,255,0.4);
    font-weight: 500;
    animation: fadeInOut 0.3s ease;
    will-change: transform, opacity;
}

@supports (backdrop-filter: blur(8px)) or (-webkit-backdrop-filter: blur(8px)) {
    .csc-toast {
        backdrop-filter: blur(10px);
        -webkit-backdrop-filter: blur(10px);
    }
}

.csc-toast.error {
    background: var(--csc-danger);
    box-shadow: 0 8px 32px rgba(239,68,68,0.3), inset 0 1px 0 rgba(255,255,255,0.4);
}

.csc-toast.info {
    background: #64748b;
    box-shadow: 0 8px 32px rgba(100,116,139,0.3), inset 0 1px 0 rgba(255,255,255,0.4);
}

@keyframes fadeInOut {
    from { opacity: 0; transform: translate(-50%, -10px); }
    to { opacity: 1; transform: translate(-50%, 0); }
}

.csc-loader {
    text-align: center;
    padding: 30px;
    font-size: 18px;
    display: none;
}

.csc-spinner {
    width: 24px;
    height: 24px;
    border: 3px solid rgba(59, 130, 246, 0.3);
    border-top: 3px solid #3b82f6;
    border-radius: 50%;
    animation: spin 1s linear infinite;
    margin: 0 auto 12px;
    will-change: transform;
}

@keyframes spin {
    to { transform: rotate(360deg); }
}
.csc-search-wrapper {
    position: relative;
    margin-bottom: 24px;
}

.csc-search-input {
    width: 100%;
    padding: 14px 45px 14px 16px;
    border: 1px solid rgba(255,255,255,0.4);
    border-radius: 12px;
    font-size: 16px;
    background: rgba(255,255,255,0.3);
    color: var(--csc-text);
    transition: all 0.2s;
    box-shadow: 0 4px 12px rgba(0,0,0,0.05), inset 0 1px 2px rgba(0,0,0,0.05);
}

@supports (backdrop-filter: blur(8px)) or (-webkit-backdrop-filter: blur(8px)) {
    .csc-search-input {
        backdrop-filter: blur(10px);
        -webkit-backdrop-filter: blur(10px);
    }
}

.csc-search-input:focus {
    outline: none;
    border-color: var(--csc-primary);
    box-shadow: 0 0 0 3px rgba(59, 130, 246, 0.2), inset 0 1px 2px rgba(0,0,0,0.05);
}

.csc-search-icon {
    position: absolute;
    right: 16px;
    top: 50%;
    transform: translateY(-50%);
    font-size: 18px;
    color: #94a3b8;
    pointer-events: none;
}

.csc-section-tiles {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(140px, 1fr));
    gap: 16px;
    margin-bottom: 24px;
    transition: opacity 0.3s ease;
}

.csc-section-tile {
    background: var(--csc-card);
    border-radius: 12px;
    padding: 16px;
    text-align: center;
    cursor: pointer;
    box-shadow: 0 4px 12px rgba(0,0,0,0.05), inset 0 1px 0 rgba(255,255,255,0.6);
    transition: all 0.2s;
    border: 1px solid var(--csc-border);
    will-change: transform;
    max-width: 280px;
    width: 100%;
}

@supports (backdrop-filter: blur(8px)) or (-webkit-backdrop-filter: blur(8px)) {
    .csc-section-tile {
        backdrop-filter: blur(10px);
        -webkit-backdrop-filter: blur(10px);
    }
}

.csc-section-tile:hover {
    transform: translateY(-2px);
    box-shadow: 0 8px 16px rgba(0,0,0,0.1), inset 0 1px 0 rgba(255,255,255,0.8);
}

.csc-section-tile.active {
    border-color: var(--csc-primary);
    background: rgba(59, 130, 246, 0.15);
    color: var(--csc-primary);
    font-weight: 600;
    box-shadow: 0 4px 12px rgba(59,130,246,0.2), inset 0 1px 0 rgba(255,255,255,0.5);
}

.csc-services-section {
    background: var(--csc-card);
    border-radius: 16px;
    padding: 16px;
    margin-bottom: 20px;
    box-shadow: var(--csc-shadow);
    display: none;
    border: 1px solid var(--csc-border);
}

@supports (backdrop-filter: blur(10px)) or (-webkit-backdrop-filter: blur(10px)) {
    .csc-services-section {
        backdrop-filter: blur(15px);
        -webkit-backdrop-filter: blur(15px);
    }
}

@media (max-width: 768px) {
    .csc-section-tiles { grid-template-columns: repeat(2, 1fr); gap: 12px; }
    .csc-section-tile { max-width: none; }
    .csc-section-tile:last-child:nth-child(odd) { grid-column: span 2; max-width: none; }
}

@media (max-width: 480px) {
    .csc-section-tiles { grid-template-columns: 1fr; }
    .csc-section-tile { max-width: none; }
    .csc-section-tile:last-child:nth-child(odd) { grid-column: span 1; }
}

.csc-services-section.active {
    display: block;
    animation: slideDown 0.3s ease;
}

@keyframes slideDown {
    from { opacity: 0; transform: translateY(-10px); }
    to { opacity: 1; transform: translateY(0); }
}

.csc-services-section h4 {
    margin-top: 0;
    margin-bottom: 15px;
    padding-bottom: 10px;
    border-bottom: 1px solid var(--csc-border);
    color: var(--csc-primary);
}

.csc-service-item {
    display: flex;
    justify-content: space-between;
    align-items: center;
    padding: 12px 0;
    border-bottom: 1px solid var(--csc-border);
    min-height: 60px;
}

.csc-service-item:last-child {
    border-bottom: none;
}

.csc-service-info {
    flex: 1;
    padding-right: 15px;
}

.csc-service-name {
    font-weight: 600;
    margin-bottom: 4px;
    line-height: 1.4;
}

.csc-service-meta {
    font-size: 13px;
    color: #64748b;
}

.csc-qty-wrapper {
    display: flex;
    align-items: center;
    justify-content: flex-end;
    width: 110px;
}

.csc-add-initial-btn {
    width: 36px;
    height: 36px;
    border-radius: 50%;
    background: var(--csc-primary);
    color: white;
    border: 1px solid rgba(255,255,255,0.3);
    font-size: 20px;
    font-weight: bold;
    cursor: pointer;
    display: flex;
    align-items: center;
    justify-content: center;
    transition: transform 0.2s, background 0.2s;
    box-shadow: 0 4px 10px rgba(59, 130, 246, 0.4), inset 0 1px 0 rgba(255,255,255,0.4);
    will-change: transform;
}

.csc-add-initial-btn:hover {
    background: var(--csc-primary-dark);
    transform: scale(1.1);
}

.csc-qty-control {
    display: none;
    align-items: center;
    border: 1px solid var(--csc-border);
    border-radius: 20px;
    background: rgba(255,255,255,0.2);
    overflow: hidden;
    box-shadow: inset 0 1px 3px rgba(0,0,0,0.1);
}

@supports (backdrop-filter: blur(8px)) or (-webkit-backdrop-filter: blur(8px)) {
    .csc-qty-control {
        backdrop-filter: blur(10px);
        -webkit-backdrop-filter: blur(10px);
    }
}

.csc-qty-control.active {
    display: flex;
}

.csc-qty-btn {
    width: 32px;
    height: 32px;
    border: none;
    background: transparent;
    color: var(--csc-primary);
    font-size: 18px;
    font-weight: bold;
    cursor: pointer;
    display: flex;
    align-items: center;
    justify-content: center;
    transition: background 0.2s;
    padding: 0;
    line-height: 1;
}

.csc-qty-btn:hover {
    background: rgba(59, 130, 246, 0.2);
}

.csc-quantity-input {
    width: 40px;
    height: 32px;
    padding: 0;
    border: none;
    text-align: center;
    font-size: 16px;
    font-weight: 600;
    background: transparent;
    color: var(--csc-text);
    -moz-appearance: textfield;
    pointer-events: none;
}

.csc-quantity-input::-webkit-outer-spin-button,
.csc-quantity-input::-webkit-inner-spin-button {
    -webkit-appearance: none;
    margin: 0;
}

.csc-summary {
    background: var(--csc-card);
    border-radius: 16px;
    padding: 20px;
    margin: 20px 0;
    box-shadow: var(--csc-shadow);
    border: 1px solid var(--csc-border);
}

@supports (backdrop-filter: blur(10px)) or (-webkit-backdrop-filter: blur(10px)) {
    .csc-summary {
        backdrop-filter: blur(15px);
        -webkit-backdrop-filter: blur(15px);
    }
}

.csc-summary-row {
    display: flex;
    justify-content: space-between;
    margin: 8px 0;
    font-size: 16px;
}

.csc-summary-label {
    font-weight: 500;
    color: var(--csc-text);
    opacity: 0.8;
}

.csc-summary-value {
    font-weight: 700;
    color: var(--csc-text);
}

.csc-discount-row {
    display: flex;
    justify-content: space-between;
    margin: 8px 0;
    color: var(--csc-success);
    font-size: 15px;
    font-weight: 500;
}

.csc-total-final {
    color: var(--csc-primary);
    font-size: 20px;
    border-top: 1px solid var(--csc-border);
    padding-top: 10px;
    margin-top: 10px;
}

.csc-promo-wrapper {
    display: flex;
    gap: 10px;
    margin-top: 15px;
}

.csc-promo-input {
    flex: 1;
    padding: 10px 14px;
    border: 1px solid rgba(255,255,255,0.4);
    border-radius: 12px;
    font-size: 15px;
    background: rgba(255,255,255,0.3);
    color: var(--csc-text);
}

@supports (backdrop-filter: blur(8px)) or (-webkit-backdrop-filter: blur(8px)) {
    .csc-promo-input {
        backdrop-filter: blur(10px);
        -webkit-backdrop-filter: blur(10px);
    }
}

.csc-promo-input:focus {
    outline: none;
    border-color: var(--csc-primary);
    box-shadow: 0 0 0 3px rgba(59,130,246,0.2);
}

.csc-promo-btn {
    padding: 10px 20px;
    background: rgba(100,116,139,0.8);
    color: white;
    border: 1px solid rgba(255,255,255,0.2);
    border-radius: 12px;
    cursor: pointer;
    font-weight: 600;
    transition: background 0.2s;
    box-shadow: 0 4px 12px rgba(0,0,0,0.1), inset 0 1px 0 rgba(255,255,255,0.3);
    will-change: transform;
}

@supports (backdrop-filter: blur(8px)) or (-webkit-backdrop-filter: blur(8px)) {
    .csc-promo-btn {
        backdrop-filter: blur(10px);
        -webkit-backdrop-filter: blur(10px);
    }
}

.csc-promo-btn:hover {
    background: #475569;
}
.csc-form-group {
    margin: 20px 0;
}

.csc-form-label {
    display: block;
    margin-bottom: 8px;
    font-weight: 600;
    font-size: 15px;
    color: var(--csc-text);
}

.csc-form-input {
    width: 100%;
    padding: 14px 16px;
    border: 1px solid rgba(255,255,255,0.4);
    border-radius: 12px;
    background: rgba(255,255,255,0.3);
    color: var(--csc-text);
    font-size: 16px;
    transition: all 0.2s;
    box-shadow: inset 0 1px 2px rgba(0,0,0,0.05);
}

@supports (backdrop-filter: blur(8px)) or (-webkit-backdrop-filter: blur(8px)) {
    .csc-form-input {
        backdrop-filter: blur(10px);
        -webkit-backdrop-filter: blur(10px);
    }
}

.csc-form-input:focus {
    outline: none;
    border-color: var(--csc-primary);
    box-shadow: 0 0 0 3px rgba(59,130,246,0.2);
}

.csc-form-input:disabled {
    background: rgba(0,0,0,0.05);
    opacity: 0.7;
    cursor: not-allowed;
}

/* === ДОБАВЛЕНО ИЗ ВЕРСИИ 2: стили для нативных select/date/time === */
#csc-calculator select,
#csc-calculator input[type="date"],
#csc-calculator input[type="time"] {
    color-scheme: light;
    color: var(--csc-ctl-fg) !important;
    background-color: var(--csc-ctl-bg) !important;
    border: 1px solid var(--csc-ctl-br) !important;
    border-radius: 12px !important;
    min-height: 44px !important;
    font-size: 15px !important;
    line-height: 1.25 !important;
    font-family: inherit !important;
    box-shadow: var(--csc-ctl-shadow) !important;
    transition: border-color .2s ease, box-shadow .2s ease, background-color .2s ease !important;
}

#csc-calculator[data-theme="dark"] select,
#csc-calculator[data-theme="dark"] input[type="date"],
#csc-calculator[data-theme="dark"] input[type="time"] {
    color-scheme: dark;
}

#csc-calculator select:focus,
#csc-calculator input[type="date"]:focus,
#csc-calculator input[type="time"]:focus {
    outline: none !important;
    border-color: var(--csc-ctl-br-hover) !important;
    box-shadow: 0 0 0 3px var(--csc-ctl-ring), var(--csc-ctl-shadow) !important;
}

#csc-calculator select {
    -webkit-appearance: none !important;
    -moz-appearance: none !important;
    appearance: none !important;
    padding: 10px 42px 10px 14px !important;
    background-image: var(--csc-ctl-arrow) !important;
    background-repeat: no-repeat !important;
    background-position: right 12px center !important;
    background-size: 16px 16px !important;
    cursor: pointer !important;
    font-weight: 600 !important;
    letter-spacing: .1px;
    text-overflow: ellipsis;
    white-space: nowrap;
    overflow: hidden;
}

#csc-calculator select:hover {
    border-color: var(--csc-ctl-br-hover) !important;
}

#csc-calculator select option {
    color: #0f172a !important;
    background: #ffffff !important;
    padding: 9px 12px !important;
    font-size: 14px !important;
    font-weight: 500 !important;
    line-height: 1.35 !important;
}

#csc-calculator[data-theme="dark"] select option {
    color: #f8fafc !important;
    background: #0b1220 !important;
}

#csc-calculator select option:checked {
    color: #ffffff !important;
    background: var(--csc-primary) !important;
}

#csc-calculator input[type="date"]::-webkit-calendar-picker-indicator,
#csc-calculator input[type="time"]::-webkit-calendar-picker-indicator {
    opacity: .85;
    cursor: pointer;
    filter: none;
    width: 18px;
    height: 18px;
    margin-right: 2px;
}

#csc-calculator[data-theme="dark"] input[type="date"]::-webkit-calendar-picker-indicator,
#csc-calculator[data-theme="dark"] input[type="time"]::-webkit-calendar-picker-indicator {
    filter: invert(1) brightness(1.2);
}

#csc-calculator input[type="date"]::-webkit-date-and-time-value,
#csc-calculator input[type="time"]::-webkit-date-and-time-value {
    color: var(--csc-ctl-fg) !important;
    text-align: left;
    min-height: 22px;
}

#csc-calculator input[type="date"]::-webkit-inner-spin-button,
#csc-calculator input[type="time"]::-webkit-inner-spin-button {
    opacity: .35;
}

/* === ДОБАВЛЕНО ИЗ ВЕРСИИ 2: кастомный dropdown для слотов времени === */
#csc-calculator .csc-select-wrap {
    position: relative;
    width: 100%;
}

#csc-calculator select.csc-native-select-hidden {
    position: absolute !important;
    width: 1px !important;
    height: 1px !important;
    padding: 0 !important;
    margin: -1px !important;
    overflow: hidden !important;
    clip: rect(0, 0, 0, 0) !important;
    white-space: nowrap !important;
    border: 0 !important;
    opacity: 0 !important;
    pointer-events: none !important;
}

#csc-calculator .csc-select-trigger {
    width: 100%;
    min-height: 44px;
    padding: 10px 14px;
    border-radius: 12px;
    background: var(--csc-ctl-bg);
    color: var(--csc-ctl-fg);
    border: 1px solid var(--csc-ctl-br);
    box-shadow: var(--csc-ctl-shadow);
    font-family: inherit;
    font-size: 15px;
    font-weight: 600;
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 10px;
    cursor: pointer;
    transition: border-color .2s ease, box-shadow .2s ease, background-color .2s ease;
}

#csc-calculator .csc-select-trigger:hover,
#csc-calculator .csc-select-wrap.open .csc-select-trigger {
    border-color: var(--csc-ctl-br-hover);
}

#csc-calculator .csc-select-trigger:focus-visible {
    outline: none;
    box-shadow: 0 0 0 3px var(--csc-ctl-ring), var(--csc-ctl-shadow);
}

#csc-calculator .csc-select-value {
    flex: 1;
    min-width: 0;
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;
    text-align: left;
}

#csc-calculator .csc-select-arrow {
    width: 16px;
    height: 16px;
    flex: 0 0 16px;
    background-image: var(--csc-ctl-arrow);
    background-size: contain;
    background-repeat: no-repeat;
    transition: transform .2s ease;
}

#csc-calculator .csc-select-wrap.open .csc-select-arrow {
    transform: rotate(180deg);
}

#csc-calculator .csc-select-panel {
    position: absolute;
    z-index: 99999;
    left: 0;
    right: 0;
    top: calc(100% + 8px);
    display: none;
    padding: 8px;
    border-radius: 14px;
    background: var(--csc-ctl-bg);
    border: 1px solid var(--csc-ctl-br);
    box-shadow: 0 18px 42px rgba(2, 6, 23, .22);
    max-height: 236px;
    overflow: auto;
    overscroll-behavior: contain;
}

#csc-calculator .csc-select-wrap.open .csc-select-panel {
    display: block;
    animation: cscSelectPop .16s ease-out;
}

@keyframes cscSelectPop {
    from { opacity: 0; transform: translateY(-6px) scale(.985); }
    to { opacity: 1; transform: none; }
}

#csc-calculator .csc-select-options {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(76px, 1fr));
    gap: 6px;
    margin: 0;
    padding: 0;
    list-style: none;
}

#csc-calculator .csc-select-option {
    padding: 8px 6px;
    border-radius: 10px;
    font-size: 13px;
    font-weight: 700;
    line-height: 1.2;
    text-align: center;
    color: var(--csc-ctl-fg);
    background: transparent;
    border: 1px solid transparent;
    cursor: pointer;
    user-select: none;
    transition: background .15s ease, border-color .15s ease, color .15s ease, transform .12s ease;
}

#csc-calculator .csc-select-option:hover:not(.placeholder):not(.disabled) {
    background: rgba(59, 130, 246, .12);
    border-color: rgba(59, 130, 246, .25);
    transform: translateY(-1px);
}

#csc-calculator .csc-select-option.selected {
    background: var(--csc-primary);
    border-color: var(--csc-primary);
    color: #fff;
    box-shadow: 0 4px 12px rgba(59, 130, 246, .28);
}

#csc-calculator .csc-select-option.disabled {
    opacity: .35;
    cursor: not-allowed;
}

#csc-calculator .csc-select-option.placeholder {
    grid-column: 1 / -1;
    text-align: left;
    font-weight: 600;
    color: var(--csc-ctl-muted);
    background: rgba(148, 163, 184, .08);
    cursor: default;
}

#csc-calculator .csc-select-panel::-webkit-scrollbar {
    width: 8px;
    height: 8px;
}

#csc-calculator .csc-select-panel::-webkit-scrollbar-thumb {
    background: rgba(100, 116, 139, .35);
    border-radius: 999px;
}

#csc-calculator[data-theme="dark"] .csc-select-panel::-webkit-scrollbar-thumb {
    background: rgba(148, 163, 184, .28);
}

@media (max-width: 480px) {
    #csc-calculator .csc-select-options {
        grid-template-columns: repeat(auto-fill, minmax(68px, 1fr));
        gap: 5px;
    }
    #csc-calculator .csc-select-option {
        padding: 7px 4px;
        font-size: 12px;
    }
}

.csc-mobile-uploads-grid {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 12px;
    margin-bottom: 8px;
}

.csc-mobile-upload {
    border: 2px dashed rgba(255,255,255,0.5);
    border-radius: 12px;
    padding: 20px;
    text-align: center;
    cursor: pointer;
    transition: all 0.2s;
    background: rgba(255,255,255,0.15);
    display: flex;
    flex-direction: column;
    align-items: center;
    justify-content: center;
    min-height: 100px;
    box-shadow: inset 0 1px 3px rgba(0,0,0,0.05);
}

@supports (backdrop-filter: blur(8px)) or (-webkit-backdrop-filter: blur(8px)) {
    .csc-mobile-upload {
        backdrop-filter: blur(10px);
        -webkit-backdrop-filter: blur(10px);
    }
}

.csc-mobile-upload:hover {
    border-color: var(--csc-primary);
    background: rgba(59, 130, 246, 0.15);
}

.csc-upload-icon {
    font-size: 28px;
    margin-bottom: 8px;
    display: block;
}

.csc-upload-text {
    font-size: 14px;
    font-weight: 500;
    color: var(--csc-primary);
}

.csc-upload-preview {
    font-size: 12px;
    color: #64748b;
    margin-top: 5px;
    text-align: center;
    min-height: 20px;
}

.csc-hidden-input {
    display: none;
}

.csc-payment-options {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 12px;
    margin-top: 10px;
}

.csc-payment-option {
    position: relative;
}

.csc-payment-option input {
    position: absolute;
    opacity: 0;
    width: 100%;
    height: 100%;
    cursor: pointer;
    z-index: 2;
}

.csc-payment-card {
    border: 1px solid var(--csc-border);
    border-radius: 12px;
    padding: 16px;
    text-align: center;
    transition: all 0.2s;
    background: var(--csc-card);
    height: 100%;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 15px;
    font-weight: 500;
    box-shadow: inset 0 1px 0 rgba(255,255,255,0.5);
}

@supports (backdrop-filter: blur(8px)) or (-webkit-backdrop-filter: blur(8px)) {
    .csc-payment-card {
        backdrop-filter: blur(10px);
        -webkit-backdrop-filter: blur(10px);
    }
}

.csc-payment-option input:checked + .csc-payment-card {
    border-color: var(--csc-primary);
    background: rgba(59, 130, 246, 0.2);
    color: var(--csc-primary);
    font-weight: 700;
    box-shadow: 0 0 0 2px rgba(59, 130, 246, 0.3), inset 0 1px 0 rgba(255,255,255,0.5);
}

.csc-checkbox-group {
    margin: 20px 0;
    font-size: 14px;
}

.csc-checkbox-label {
    display: flex;
    align-items: flex-start;
    gap: 10px;
    margin-bottom: 12px;
    cursor: pointer;
    line-height: 1.4;
    color: var(--csc-text);
}

.csc-checkbox-label input {
    margin-top: 3px;
    width: 18px;
    height: 18px;
}

.csc-submit-btn {
    background: linear-gradient(135deg, var(--csc-primary), var(--csc-primary-dark));
    color: white;
    border: 1px solid rgba(255,255,255,0.3);
    padding: 18px;
    border-radius: 14px;
    font-size: 18px;
    font-weight: 600;
    cursor: pointer;
    width: 100%;
    margin-top: 15px;
    box-shadow: 0 8px 20px rgba(59, 130, 246, 0.4), inset 0 1px 0 rgba(255,255,255,0.4);
    transition: all 0.2s ease;
    will-change: transform;
}

.csc-submit-btn:active {
    transform: scale(0.98);
    box-shadow: 0 2px 4px rgba(0,0,0,0.1), inset 0 1px 0 rgba(255,255,255,0.4);
}

.csc-submit-btn:disabled {
    opacity: 0.7;
    cursor: not-allowed;
    transform: none;
    background: #94a3b8;
    box-shadow: none;
}

#csc-result-screen {
    display: none;
    text-align: center;
    animation: fadeIn 0.5s ease;
}

@keyframes fadeIn {
    from { opacity: 0; }
    to { opacity: 1; }
}

.csc-receipt-card {
    background: var(--csc-card);
    padding: 30px;
    border-radius: 20px;
    box-shadow: 0 10px 30px rgba(0,0,0,0.1), inset 0 1px 0 rgba(255,255,255,0.6);
    margin-bottom: 25px;
    border: 1px solid var(--csc-border);
    text-align: left;
    color: var(--csc-text);
}

@supports (backdrop-filter: blur(10px)) or (-webkit-backdrop-filter: blur(10px)) {
    .csc-receipt-card {
        backdrop-filter: blur(15px);
        -webkit-backdrop-filter: blur(15px);
    }
}

.csc-receipt-icon {
    font-size: 56px;
    margin-bottom: 15px;
    display: block;
    text-align: center;
}

.csc-receipt-title {
    font-size: 24px;
    font-weight: 800;
    margin-bottom: 15px;
    color: var(--csc-success);
    text-align: center;
}

.csc-receipt-number {
    font-size: 20px;
    font-weight: 700;
    background: rgba(255,255,255,0.3);
    padding: 12px 24px;
    border-radius: 10px;
    display: inline-block;
    margin: 10px auto;
    color: var(--csc-text);
    letter-spacing: 0.5px;
    text-align: center;
    border: 1px solid rgba(255,255,255,0.4);
}

@supports (backdrop-filter: blur(8px)) or (-webkit-backdrop-filter: blur(8px)) {
    .csc-receipt-number {
        backdrop-filter: blur(10px);
        -webkit-backdrop-filter: blur(10px);
    }
}

.csc-receipt-text {
    color: var(--csc-text);
    opacity: 0.8;
    margin-top: 15px;
    line-height: 1.6;
    font-size: 15px;
    text-align: center;
}

.csc-download-btn {
    background: rgba(51,65,85,0.8);
    color: #fff;
    border: 1px solid rgba(255,255,255,0.2);
    padding: 12px 24px;
    border-radius: 10px;
    cursor: pointer;
    font-weight: 600;
    margin-top: 15px;
    display: inline-flex;
    align-items: center;
    gap: 8px;
    transition: background 0.2s;
    box-shadow: 0 4px 12px rgba(0,0,0,0.1), inset 0 1px 0 rgba(255,255,255,0.2);
    will-change: transform;
}

@supports (backdrop-filter: blur(8px)) or (-webkit-backdrop-filter: blur(8px)) {
    .csc-download-btn {
        backdrop-filter: blur(10px);
        -webkit-backdrop-filter: blur(10px);
    }
}

.csc-download-btn:hover {
    background: rgba(30,41,59,0.9);
}

.csc-save-hint {
    color: var(--csc-danger);
    font-weight: 600;
    margin-top: 25px;
    font-size: 15px;
}

.csc-progress-overlay {
    display: none;
    position: fixed;
    top: 0;
    left: 0;
    width: 100%;
    height: 100%;
    background: rgba(15, 23, 42, 0.4);
    z-index: 999999;
    align-items: center;
    justify-content: center;
}

@supports (backdrop-filter: blur(8px)) or (-webkit-backdrop-filter: blur(8px)) {
    .csc-progress-overlay {
        backdrop-filter: blur(12px);
        -webkit-backdrop-filter: blur(12px);
    }
}

.csc-progress-overlay.active {
    display: flex;
    animation: fadeIn 0.3s ease;
}

.csc-progress-card {
    background: var(--csc-card);
    border-radius: 20px;
    padding: 32px;
    width: 90%;
    max-width: 420px;
    box-shadow: 0 20px 40px rgba(0, 0, 0, 0.15), inset 0 1px 0 rgba(255,255,255,0.6);
    text-align: center;
    border: 1px solid var(--csc-border);
}

@supports (backdrop-filter: blur(10px)) or (-webkit-backdrop-filter: blur(10px)) {
    .csc-progress-card {
        backdrop-filter: blur(20px);
        -webkit-backdrop-filter: blur(20px);
    }
}

.csc-progress-title {
    font-size: 18px;
    font-weight: 700;
    color: var(--csc-text);
    margin-bottom: 24px;
}

.csc-progress-steps {
    display: flex;
    flex-direction: column;
    gap: 0;
    text-align: left;
}

.csc-progress-step {
    display: flex;
    align-items: center;
    gap: 14px;
    padding: 12px 0;
    position: relative;
}

.csc-progress-step:not(:last-child)::after {
    content: '';
    position: absolute;
    left: 19px;
    top: 44px;
    width: 2px;
    height: calc(100% - 20px);
    background: var(--csc-border);
}

.csc-progress-step.completed:not(:last-child)::after {
    background: var(--csc-success);
}

.csc-progress-icon {
    width: 40px;
    height: 40px;
    border-radius: 50%;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 18px;
    background: rgba(255,255,255,0.3);
    border: 1px solid var(--csc-border);
    color: #94a3b8;
    transition: all 0.3s ease;
    flex-shrink: 0;
    position: relative;
    z-index: 1;
    box-shadow: inset 0 1px 0 rgba(255,255,255,0.5);
    will-change: transform;
}

@supports (backdrop-filter: blur(4px)) or (-webkit-backdrop-filter: blur(4px)) {
    .csc-progress-icon {
        backdrop-filter: blur(5px);
        -webkit-backdrop-filter: blur(5px);
    }
}

.csc-progress-step.active .csc-progress-icon {
    border-color: var(--csc-primary);
    background: rgba(59, 130, 246, 0.2);
    color: var(--csc-primary);
    animation: cscPulse 1.5s ease infinite;
}

.csc-progress-step.completed .csc-progress-icon {
    border-color: var(--csc-success);
    background: var(--csc-success);
    color: white;
    box-shadow: 0 4px 12px rgba(16,185,129,0.3), inset 0 1px 0 rgba(255,255,255,0.4);
}

.csc-progress-step.error .csc-progress-icon {
    border-color: var(--csc-danger);
    background: rgba(239, 68, 68, 0.2);
    color: var(--csc-danger);
}

@keyframes cscPulse {
    0%, 100% { box-shadow: 0 0 0 0 rgba(59, 130, 246, 0.3); }
    50% { box-shadow: 0 0 0 8px rgba(59, 130, 246, 0); }
}

.csc-progress-label {
    font-size: 14px;
    font-weight: 500;
    color: #94a3b8;
    transition: color 0.3s ease;
}

.csc-progress-step.active .csc-progress-label {
    color: var(--csc-text);
    font-weight: 600;
}

.csc-progress-step.completed .csc-progress-label {
    color: var(--csc-success);
}

.csc-progress-step.error .csc-progress-label {
    color: var(--csc-danger);
}

.csc-progress-error-msg {
    margin-top: 16px;
    padding: 12px;
    background: rgba(239, 68, 68, 0.1);
    border: 1px solid rgba(239, 68, 68, 0.3);
    border-radius: 10px;
    color: var(--csc-danger);
    font-size: 13px;
    display: none;
    word-break: break-word;
}

@supports (backdrop-filter: blur(4px)) or (-webkit-backdrop-filter: blur(4px)) {
    .csc-progress-error-msg {
        backdrop-filter: blur(5px);
        -webkit-backdrop-filter: blur(5px);
    }
}

.csc-progress-error-msg.visible {
    display: block;
}

.csc-progress-close-btn {
    margin-top: 20px;
    padding: 10px 24px;
    background: rgba(255,255,255,0.2);
    border: 1px solid var(--csc-border);
    border-radius: 10px;
    color: var(--csc-text);
    cursor: pointer;
    font-size: 14px;
    font-weight: 500;
    display: none;
    box-shadow: inset 0 1px 0 rgba(255,255,255,0.4);
    will-change: transform;
}

@supports (backdrop-filter: blur(8px)) or (-webkit-backdrop-filter: blur(8px)) {
    .csc-progress-close-btn {
        backdrop-filter: blur(10px);
        -webkit-backdrop-filter: blur(10px);
    }
}

.csc-push-subscribe-wrapper {
    margin: 16px 0;
    width: 100%;
}

.csc-push-subscribe-btn {
    display: flex;
    align-items: center;
    gap: 12px;
    width: 100%;
    padding: 14px 18px;
    background: linear-gradient(135deg, rgba(59,130,246,.08), rgba(99,102,241,.08));
    border: 1.5px dashed var(--csc-primary);
    border-radius: 12px;
    color: var(--csc-primary);
    font-size: 14px;
    font-weight: 600;
    cursor: pointer;
    transition: all .25s ease;
    box-shadow: 0 2px 8px rgba(59,130,246,.06);
    box-sizing: border-box;
    text-align: left;
    line-height: 1.4;
}

.csc-push-subscribe-btn:hover {
    background: linear-gradient(135deg, rgba(59,130,246,.15), rgba(99,102,241,.15));
    box-shadow: 0 4px 12px rgba(59,130,246,.15);
    transform: translateY(-1px);
}

.csc-push-subscribe-btn .csc-push-icon {
    font-size: 20px;
    line-height: 1;
    flex-shrink: 0;
    animation: cscBellPulse 2s ease-in-out infinite;
}

.csc-push-subscribe-btn .csc-push-text {
    flex: 1;
    min-width: 0;
    white-space: normal;
    word-break: break-word;
}

.csc-push-subscribe-btn .csc-push-status {
    flex-shrink: 0;
    font-size: 10px;
    font-weight: 700;
    text-transform: uppercase;
    letter-spacing: .5px;
    color: #fff;
    background: #94a3b8;
    padding: 3px 8px;
    border-radius: 10px;
    line-height: 1;
    white-space: nowrap;
}

.csc-push-subscribe-btn.subscribed {
    background: linear-gradient(135deg, rgba(16,185,129,.1), rgba(5,150,105,.08));
    border-color: var(--csc-success);
    border-style: solid;
    color: var(--csc-success);
    cursor: default;
}

.csc-push-subscribe-btn.subscribed .csc-push-icon {
    animation: none;
}

.csc-push-subscribe-btn.subscribed .csc-push-status {
    background: var(--csc-success);
}

.csc-push-subscribe-btn.loading {
    opacity: .75;
    cursor: wait;
    pointer-events: none;
}

.csc-push-subscribe-btn.loading .csc-push-icon {
    animation: cscSpin 1s linear infinite;
}

.csc-push-subscribe-btn.error {
    background: linear-gradient(135deg, rgba(239,68,68,.08), rgba(220,38,38,.06));
    border-color: var(--csc-danger);
    color: var(--csc-danger);
}

.csc-push-subscribe-btn.error .csc-push-status {
    background: var(--csc-danger);
}

@keyframes cscBellPulse {
    0%, 100% { transform: scale(1); }
    50% { transform: scale(1.15) rotate(-8deg); }
}

@keyframes cscSpin {
    from { transform: rotate(0deg); }
    to { transform: rotate(360deg); }
}

#csc-calculator[data-theme="dark"] .csc-push-subscribe-btn {
    background: linear-gradient(135deg, rgba(59,130,246,.15), rgba(99,102,241,.15));
    border-color: rgba(96,165,250,.5);
}

#csc-calculator[data-theme="dark"] .csc-push-subscribe-btn.subscribed {
    background: linear-gradient(135deg, rgba(16,185,129,.15), rgba(5,150,105,.12));
    border-color: rgba(52,211,153,.6);
}

/* ============================================================
   ОПТИМИЗАЦИЯ ДЛЯ МОБИЛЬНЫХ УСТРОЙСТВ
   ============================================================ */
@media (max-width: 768px) {
    #csc-calculator {
        border-radius: 16px;
        padding: 12px;
    }

    @supports (backdrop-filter: blur(8px)) or (-webkit-backdrop-filter: blur(8px)) {
        #csc-calculator,
        .csc-theme-toggle,
        .csc-search-input,
        .csc-section-tile,
        .csc-services-section,
        .csc-qty-control,
        .csc-summary,
        .csc-promo-input,
        .csc-promo-btn,
        .csc-form-input,
        .csc-mobile-upload,
        .csc-payment-card,
        .csc-receipt-card,
        .csc-receipt-number,
        .csc-download-btn,
        .csc-progress-card,
        .csc-progress-close-btn,
        .csc-push-subscribe-btn {
            backdrop-filter: blur(8px);
            -webkit-backdrop-filter: blur(8px);
        }
    }

    .csc-section-tiles {
        grid-template-columns: repeat(auto-fill, minmax(120px, 1fr));
        gap: 12px;
    }

    .csc-mobile-uploads-grid {
        grid-template-columns: 1fr;
    }

    .csc-payment-options {
        grid-template-columns: 1fr;
    }
}

/* Дополнительное упрощение для очень слабых устройств */
@media (max-width: 480px) {
    @supports (backdrop-filter: blur(4px)) or (-webkit-backdrop-filter: blur(4px)) {
        #csc-calculator,
        .csc-theme-toggle,
        .csc-search-input,
        .csc-section-tile,
        .csc-services-section,
        .csc-qty-control,
        .csc-summary,
        .csc-promo-input,
        .csc-promo-btn,
        .csc-form-input,
        .csc-mobile-upload,
        .csc-payment-card,
        .csc-receipt-card,
        .csc-receipt-number,
        .csc-download-btn,
        .csc-progress-card,
        .csc-progress-close-btn,
        .csc-push-subscribe-btn {
            backdrop-filter: blur(6px);
            -webkit-backdrop-filter: blur(6px);
        }
    }

    .csc-push-subscribe-btn {
        padding: 10px 14px;
        font-size: 13px;
    }

    .csc-push-subscribe-btn .csc-push-icon {
        font-size: 18px;
    }
}

@media (max-width: 480px) {
    .csc-push-subscribe-btn {
        padding: 12px 14px;
        font-size: 13px;
        gap: 10px;
    }
    .csc-push-subscribe-btn .csc-push-icon {
        font-size: 18px;
    }
    .csc-push-subscribe-btn .csc-push-status {
        font-size: 9px;
        padding: 2px 6px;
    }
}

/* Отключение эффектов при включенной экономии энергии (reduce motion) */
@media (prefers-reduced-motion: reduce) {
    *, *::before, *::after {
        animation-duration: 0.01ms !important;
        animation-iteration-count: 1 !important;
        transition-duration: 0.01ms !important;
    }
}
</style>
<div id="csc-calculator" data-theme="light">
<button id="csc-theme-toggle" class="csc-theme-toggle" aria-label="Переключить тему">🌙</button>
<div class="csc-toast" id="csc-toast"></div>
<div class="csc-loader" id="csc-loader">
<div class="csc-spinner"></div>
Загрузка сервисов...
</div>
<div id="csc-result-screen">
<div class="csc-receipt-card" id="csc-receipt-node">
<div class="csc-receipt-icon">✅</div>
<div class="csc-receipt-title">Ваша заявка успешно отправлена!</div>
<div style="text-align: center;">
<div id="csc-receipt-number-display" class="csc-receipt-number">Номер заявки: ...</div>
</div>
<div id="csc-receipt-details"></div>
<p class="csc-receipt-text">Заявка передана специалисту. Он свяжется с вами в ближайшее время для подтверждения.</p>
</div>
<button type="button" class="csc-download-btn" onclick="cscDownloadReceipt()">
📥 Скачать чек заявки
</button>
<p class="csc-save-hint">Обязательно сохраните номер заявки!</p>
<button type="button" class="csc-submit-btn" style="margin-top:30px;background:#64748b;" onclick="location.reload()">Создать новую заявку</button>
</div>
<div id="csc-form-container"></div>
</div>

<!-- ============================================================
УЛУЧШЕНИЕ №6: ПРОГРЕСС-БАР ОТПРАВКИ ЗАЯВКИ
============================================================ -->
<div class="csc-progress-overlay" id="csc-progress-overlay">
<div class="csc-progress-card">
<div class="csc-progress-title">Отправка заявки</div>
<div class="csc-progress-steps">
<div class="csc-progress-step" id="csc-step-validate" data-step="1">
<div class="csc-progress-icon">📋</div>
<span class="csc-progress-label">Проверяем данные</span>
</div>
<div class="csc-progress-step" id="csc-step-booking" data-step="2">
<div class="csc-progress-icon">📅</div>
<span class="csc-progress-label">Бронируем слот</span>
</div>
<div class="csc-progress-step" id="csc-step-dispatch" data-step="3">
<div class="csc-progress-icon">📤</div>
<span class="csc-progress-label">Отправляем диспетчеру</span>
</div>
<div class="csc-progress-step" id="csc-step-done" data-step="4">
<div class="csc-progress-icon">✅</div>
<span class="csc-progress-label">Готово!</span>
</div>
</div>
<div class="csc-progress-error-msg" id="csc-progress-error"></div>
<button type="button" class="csc-progress-close-btn" id="csc-progress-close" onclick="cscCloseProgressOverlay()">Закрыть</button>
</div>
</div>
<script>
document.addEventListener('DOMContentLoaded', function() {
    // ==========================================
    // ЛОГИКА ТЕМЫ (Светлая / Темная)
    // ==========================================
    const calcContainer = document.getElementById('csc-calculator');
    const themeBtn = document.getElementById('csc-theme-toggle');

    function applyTheme(theme) {
        calcContainer.setAttribute('data-theme', theme);
        localStorage.setItem('csc_calc_theme', theme);
        themeBtn.innerHTML = theme === 'dark' ? '☀️' : '🌙';
    }

    const savedTheme = localStorage.getItem('csc_calc_theme');
    if (savedTheme) {
        applyTheme(savedTheme);
    } else {
        const prefersDark = window.matchMedia && window.matchMedia('(prefers-color-scheme: dark)').matches;
        applyTheme(prefersDark ? 'dark' : 'light');
    }

    themeBtn.addEventListener('click', () => {
        const curTheme = calcContainer.getAttribute('data-theme');
        applyTheme(curTheme === 'dark' ? 'light' : 'dark');
    });

    if (window.matchMedia) {
        window.matchMedia('(prefers-color-scheme: dark)').addEventListener('change', e => {
            if (!localStorage.getItem('csc_calc_theme')) {
                applyTheme(e.matches ? 'dark' : 'light');
            }
        });
    }

    // ============================================================
    // УЛУЧШЕНИЕ: ПОДПИСКА КЛИЕНТА НА УВЕДОМЛЕНИЯ О СТАТУСЕ ЗАЯВКИ
    // ============================================================
    function cscUrlBase64ToUint8Array(base64String) {
        if (!base64String) return null;
        const padding = '='.repeat((4 - base64String.length % 4) % 4);
        const base64 = (base64String + padding).replace(/-/g, '+').replace(/_/g, '/');
        try {
            const rawData = window.atob(base64);
            const outputArray = new Uint8Array(rawData.length);
            for (let i = 0; i < rawData.length; ++i) outputArray[i] = rawData.charCodeAt(i);
            return outputArray;
        } catch (e) { return null; }
    }

    async function cscSubscribeCustomerToPush() {
        const btn = document.getElementById('csc-push-subscribe-btn');
        const statusEl = document.getElementById('csc-push-status');
        if (!btn) return;

        const isSecure = location.protocol === 'https:' || location.hostname === 'localhost' || location.hostname === '127.0.0.1';
        if (!isSecure) {
            btn.classList.add('error');
            btn.querySelector('.csc-push-icon').textContent = '🔒';
            btn.querySelector('.csc-push-text').textContent = 'Требуется HTTPS для уведомлений';
            if (statusEl) statusEl.textContent = 'https';
            showNotice('🔒 Push-уведомления работают только по HTTPS. Обратитесь к администратору сайта.', 'error');
            return;
        }

        if (!('serviceWorker' in navigator) || !('PushManager' in window)) {
            btn.classList.add('error');
            btn.querySelector('.csc-push-icon').textContent = '⚠️';
            btn.querySelector('.csc-push-text').textContent = 'Браузер не поддерживает уведомления';
            if (statusEl) statusEl.textContent = 'нет';
            return;
        }

        const vapidKey = '<?php echo esc_js(get_option("csc_vapid_public_key", "")); ?>';
        if (!vapidKey) {
            btn.classList.add('error');
            btn.querySelector('.csc-push-text').textContent = 'Push не настроен на сервере';
            return;
        }

        const phoneField = document.getElementById('csc-phone');
        const phoneRaw = phoneField ? phoneField.value.trim() : '';
        const phoneNorm = phoneRaw.replace(/\D/g, '');
        if (phoneNorm.length < 10) {
            showNotice('Сначала введите номер телефона', 'error');
            if (phoneField) phoneField.focus();
            return;
        }

        if (btn.classList.contains('loading') || btn.classList.contains('subscribed')) return;

        btn.classList.remove('error');
        btn.classList.add('loading');
        btn.querySelector('.csc-push-icon').textContent = '⏳';
        btn.querySelector('.csc-push-text').textContent = 'Подключение...';
        if (statusEl) statusEl.textContent = '';

        try {
            const permission = await Notification.requestPermission();
            if (permission !== 'granted') {
                throw new Error('Permission denied');
            }

            let reg = await navigator.serviceWorker.getRegistration('/');
            if (!reg || !reg.active) {
                reg = await navigator.serviceWorker.register('/csc-sw.js', { scope: '/' });
                await navigator.serviceWorker.ready;
            }

            let sub = await reg.pushManager.getSubscription();
            if (!sub || sub.expirationTime) {
                const vapidArray = cscUrlBase64ToUint8Array(vapidKey);
                if (!vapidArray) throw new Error('Invalid VAPID key');
                sub = await reg.pushManager.subscribe({
                    userVisibleOnly: true,
                    applicationServerKey: vapidArray
                });
            }

            const fd = new FormData();
            fd.append('action', 'csc_push_subscribe');
            fd.append('user_type', 'customer');
            fd.append('user_id', phoneNorm);
            fd.append('subscription', JSON.stringify(sub));

            const resp = await fetch('<?php echo admin_url("admin-ajax.php"); ?>', {
                method: 'POST',
                body: fd
            });
            const result = await resp.json();

            if (result && result.success) {
                btn.classList.remove('loading');
                btn.classList.add('subscribed');
                btn.querySelector('.csc-push-icon').textContent = '✅';
                btn.querySelector('.csc-push-text').textContent = 'Уведомления о статусе включены';
                if (statusEl) statusEl.textContent = 'активны';
                showNotice('🔔 Уведомления включены! Вы получите сообщение о статусе заявки.', 'info');
            } else {
                throw new Error(result?.data?.message || 'Server error');
            }
        } catch (err) {
            console.warn('[CSC] Push subscribe error:', err);
            btn.classList.remove('loading');
            btn.classList.add('error');
            btn.querySelector('.csc-push-icon').textContent = '⚠️';
            btn.querySelector('.csc-push-text').textContent =
                err.message === 'Permission denied'
                    ? 'Разрешите уведомления в настройках браузера'
                    : 'Ошибка. Нажмите ещё раз';
            if (statusEl) statusEl.textContent = '';
        }
    }

    const pushBtn = document.getElementById('csc-push-subscribe-btn');
    if (pushBtn) {
        pushBtn.addEventListener('click', cscSubscribeCustomerToPush);
    }

    // ==========================================
    // Переменные из PHP
    const isPromoActive = <?php echo $is_promo_active ? 'true' : 'false'; ?>;
    const minOrderAmount = <?php echo $min_order_amount; ?>;
    const toast = document.getElementById('csc-toast');
    const showNotice = (text, type = 'normal') => {
        toast.textContent = text;
        toast.className = 'csc-toast ' + (type === 'error' ? 'error' : (type === 'info' ? 'info' : ''));
        toast.style.display = 'block';
        setTimeout(() => {
            toast.style.display = 'none';
        }, 4000);
    };
    let totalDuration = 0;
    let totalPriceBase = 0;
    let currentFinalTotal = 0;

    // ============================================================
    // УЛУЧШЕНИЕ №6: ФУНКЦИИ УПРАВЛЕНИЯ ПРОГРЕСС-БАРОМ
    // ============================================================
    function cscShowProgress() {
        const overlay = document.getElementById('csc-progress-overlay');
        overlay.classList.add('active');
        document.querySelectorAll('.csc-progress-step').forEach(step => {
            step.classList.remove('active', 'completed', 'error');
        });
        document.getElementById('csc-progress-error').classList.remove('visible');
        document.getElementById('csc-progress-error').textContent = '';
        document.getElementById('csc-progress-close').classList.remove('visible');
    }

    function cscSetProgressStep(stepNum, state) {
        const stepEl = document.getElementById('csc-step-' + stepNum);
        if (!stepEl) return;
        stepEl.classList.remove('active', 'completed', 'error');
        if (state) stepEl.classList.add(state);
    }

    function cscProgressStepActive(stepNum) {
        document.querySelectorAll('.csc-progress-step').forEach(step => {
            const sNum = parseInt(step.getAttribute('data-step'));
            if (sNum < stepNum) {
                step.classList.remove('active', 'error');
                step.classList.add('completed');
            } else if (sNum === stepNum) {
                step.classList.remove('completed', 'error');
                step.classList.add('active');
            } else {
                step.classList.remove('active', 'completed', 'error');
            }
        });
    }

    function cscProgressStepCompleted(stepNum) {
        const stepEl = document.querySelector('.csc-progress-step[data-step="' + stepNum + '"]');
        if (stepEl) {
            stepEl.classList.remove('active', 'error');
            stepEl.classList.add('completed');
        }
    }

    function cscProgressError(stepNum, errorMsg) {
        const stepEl = document.querySelector('.csc-progress-step[data-step="' + stepNum + '"]');
        if (stepEl) {
            stepEl.classList.remove('active', 'completed');
            stepEl.classList.add('error');
        }
        const errEl = document.getElementById('csc-progress-error');
        errEl.textContent = errorMsg || 'Произошла ошибка при отправке заявки.';
        errEl.classList.add('visible');
        document.getElementById('csc-progress-close').classList.add('visible');
    }

    function cscProgressSuccess() {
        document.querySelectorAll('.csc-progress-step').forEach(step => {
            step.classList.remove('active', 'error');
            step.classList.add('completed');
        });
        setTimeout(() => {
            document.getElementById('csc-progress-overlay').classList.remove('active');
        }, 800);
    }

    function cscCloseProgressOverlay() {
        document.getElementById('csc-progress-overlay').classList.remove('active');
    }

    function loadForm() {
        const loader = document.getElementById('csc-loader');
        loader.style.display = 'block';
        fetch('<?php echo admin_url("admin-ajax.php"); ?>', {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: 'action=csc_get_form_data'
        })
        .then(r => r.json())
        .then(res => {
            if (!res.success) {
                showNotice('Ошибка загрузки данных', 'error');
                return;
            }
            loader.style.display = 'none';
            renderForm(res.data.sections || {}, res.data.items || {});
        })
        .catch(() => {
            loader.style.display = 'none';
            showNotice('Ошибка сети', 'error');
        });
    }
	    function renderForm(sections, items) {
        let html = `
<div class="csc-search-wrapper">
<input type="text" id="csc-service-search" class="csc-search-input" placeholder="🔍 Найти услугу (например: кран, засор)...">
<span class="csc-search-icon">🔍</span>
</div>
`;
        html += '<div class="csc-section-tiles" id="csc-section-tiles">';
        for (const secId in sections) {
            html += `<div class="csc-section-tile" data-section="${secId}">${sections[secId].name}</div>`;
        }
        html += '</div>';
        html += '<div id="csc-no-results" style="display:none; text-align:center; padding:20px; color:#64748b;">Ничего не найдено по вашему запросу.</div>';
        for (const secId in sections) {
            html += `<div class="csc-services-section" id="section-${secId}"><h4>${sections[secId].name}</h4>`;
            let hasItems = false;
            for (const itemId in items) {
                const item = items[itemId];
                if (item.section_id === secId && item.is_available) {
                    hasItems = true;
                    html += `
<div class="csc-service-item">
<div class="csc-service-info">
<div class="csc-service-name">${item.name}</div>
<div class="csc-service-meta">${Number(item.price).toLocaleString()} ₽ | ${item.duration} мин</div>
</div>
<div class="csc-qty-wrapper">
<button type="button" class="csc-add-initial-btn">+</button>
<div class="csc-qty-control">
<button type="button" class="csc-qty-btn csc-minus">-</button>
<input type="number" name="quantity_${itemId}" min="0" value="0" readonly
data-price="${item.price}" data-duration="${item.duration}"
class="csc-quantity-input">
<button type="button" class="csc-qty-btn csc-plus">+</button>
</div>
</div>
</div>
`;
                }
            }
            if (!hasItems) {
                html += '<p>В этом разделе пока нет доступных услуг.</p>';
            }
            html += '</div>';
        }
        html += `
<div class="csc-summary">
<div class="csc-summary-row">
<span class="csc-summary-label">Сумма${isPromoActive ? ' (без скидок)' : ''}:</span>
<strong id="csc-base-total" class="csc-summary-value">0 ₽</strong>
</div>
${isPromoActive ? '<div id="csc-discounts-block"></div>' : ''}
<div class="csc-summary-row csc-total-final">
<span class="csc-summary-label">Итого к оплате:</span>
<strong id="csc-total" class="csc-summary-value">0 ₽</strong>
</div>
<div id="csc-min-order-warning" style="display:none; color: var(--csc-danger); font-size: 13px; margin-top: 5px; text-align: right; font-weight: 500;">
Минимальная сумма заказа: ${minOrderAmount} ₽
</div>
<div class="csc-summary-row" style="margin-top: 15px;">
<span class="csc-summary-label">Время выполнения:</span>
<strong id="csc-duration" class="csc-summary-value">0 ч 0 мин</strong>
</div>
${isPromoActive ? `
<div class="csc-promo-wrapper">
<input type="text" id="csc-promo-code" class="csc-promo-input" placeholder="Ввести промокод">
<button type="button" class="csc-promo-btn" id="csc-apply-promo">Применить</button>
</div>` : ''}
</div>
<form id="csc-final-form" enctype="multipart/form-data">
<div class="csc-form-group">
<label class="csc-form-label">Ваше имя (обязательно):</label>
<input type="text" name="name" required minlength="2" maxlength="50" class="csc-form-input" placeholder="Иван Иванов">
</div>
<div class="csc-form-group">
<label class="csc-form-label">Телефон (обязательно):</label>
<input type="tel" name="phone" id="csc-phone" pattern="[+]?[0-9]{10,15}" required placeholder="+7 (999) 123-45-67" class="csc-form-input">
</div>
<div class="csc-form-group">
<label class="csc-form-label">Email:</label>
<input type="email" name="email" class="csc-form-input" placeholder="mail@example.com">
</div>
<div class="csc-form-group">
    <label class="csc-form-label">Адрес (обязательно):</label>
    <input type="text" name="address" required class="csc-form-input"
           placeholder="Город, улица, дом"
           id="csc-address-input">
    <small id="csc-address-hint" style="display:block; margin-top:4px; font-size:12px; color:var(--csc-text-secondary);">
        📍 Формат: <b>Город, Улица, Дом</b> — например: «Москва, ул. Тверская, 7»
    </small>
</div>
<div class="csc-form-group">
<label class="csc-form-label">Прикрепить файлы:</label>
<div class="csc-mobile-uploads-grid">
<label class="csc-mobile-upload">
<input type="file" name="photos[]" multiple accept="image/*" class="csc-hidden-input" onchange="cscCheckFiles(this, 10, 0); cscUpdatePreview(this, 'photo-preview')">
<span class="csc-upload-icon">📷</span>
<span class="csc-upload-text">Добавить фото</span>
</label>
<label class="csc-mobile-upload">
<input type="file" name="video" accept="video/*" class="csc-hidden-input" onchange="cscCheckFiles(this, 1, 50); cscUpdatePreview(this, 'video-preview')">
<span class="csc-upload-icon">🎥</span>
<span class="csc-upload-text">Добавить видео</span>
</label>
</div>
<div id="photo-preview" class="csc-upload-preview"></div>
<div id="video-preview" class="csc-upload-preview"></div>
<div class="csc-upload-info" style="text-align:center; font-size:12px; color:#64748b;">Фото до 10 шт. Видео до 50 Мб.</div>
</div>
<div class="csc-form-group">
<label class="csc-form-label">Желаемая дата:</label>
<input type="date" id="csc-date" name="date" disabled min="<?php echo esc_attr($min_date); ?>" max="<?php echo esc_attr(wp_date('Y-m-d', strtotime('+30 days'))); ?>" required class="csc-form-input" title="Сначала выберите услуги">
<small style="color:#64748b; font-size:12px;">Выбор даты станет доступен после выбора услуг</small>
</div>
<div class="csc-form-group">
<label class="csc-form-label">Время начала:</label>
<select id="csc-time" name="time" required class="csc-form-input">
<option value="">Выберите дату</option>
</select>
</div>
<div class="csc-form-group">
<label class="csc-form-label">Способ оплаты:</label>
<div class="csc-payment-options">
<div class="csc-payment-option">
<input type="radio" name="payment_method" value="cash" checked>
<div class="csc-payment-card">Наличные / Перевод</div>
</div>
<?php if ($enable_sbp): ?>
<div class="csc-payment-option">
<input type="radio" name="payment_method" value="sbp">
<div class="csc-payment-card" style="flex-direction: column;">
<span style="font-weight:700;">СБП</span>
<span style="font-size:12px;">Быстрая оплата</span>
</div>
</div>
<?php endif; ?>
</div>
</div>

<!-- ============================================================
    УЛУЧШЕНИЕ: КНОПКА ПОДПИСКИ НА УВЕДОМЛЕНИЯ О СТАТУСЕ ЗАЯВКИ
============================================================ -->
<div class="csc-push-subscribe-wrapper">
    <button type="button" class="csc-push-subscribe-btn" id="csc-push-subscribe-btn">
        <span class="csc-push-icon">🔔</span>
        <span class="csc-push-text">Получать уведомления о статусе заявки</span>
        <span class="csc-push-status" id="csc-push-status">выкл</span>
    </button>
</div>

<div class="csc-checkbox-group">
    <label class="csc-checkbox-label">
        <input type="checkbox" name="agree_privacy" required>
        <span>Я даю согласие на обработку персональных данных. <a href="/privacy-policy/" target="_blank">Политика конфиденциальности</a></span>
    </label>
    <label class="csc-checkbox-label">
        <input type="checkbox" name="agree_rules" required>
        <span>Я ознакомлен(а) и согласен(а) с <a href="/pravila-servisa/" target="_blank">правилами сервиса</a></span>
    </label>
</div>
<button type="submit" class="csc-submit-btn">Отправить заявку</button>
</form>
`;
        document.getElementById('csc-form-container').innerHTML = html;
        setupEventHandlers();
    }

    function setupEventHandlers() {
        const searchInput = document.getElementById('csc-service-search');
        const tilesContainer = document.getElementById('csc-section-tiles');
        const noResultsMsg = document.getElementById('csc-no-results');

        searchInput.addEventListener('input', function(e) {
            const term = e.target.value.toLowerCase().trim();
            const sections = document.querySelectorAll('.csc-services-section');
            if (term.length > 0) {
                tilesContainer.style.display = 'none';
                let globalMatch = false;
                sections.forEach(sec => {
                    let hasVisible = false;
                    const items = sec.querySelectorAll('.csc-service-item');
                    items.forEach(item => {
                        const name = item.querySelector('.csc-service-name').textContent.toLowerCase();
                        if (name.includes(term)) {
                            item.style.display = 'flex';
                            hasVisible = true;
                            globalMatch = true;
                        } else {
                            item.style.display = 'none';
                        }
                    });
                    if (hasVisible) {
                        sec.classList.add('active');
                        sec.style.display = 'block';
                    } else {
                        sec.classList.remove('active');
                        sec.style.display = 'none';
                    }
                });
                noResultsMsg.style.display = globalMatch ? 'none' : 'block';
            } else {
                tilesContainer.style.display = 'grid';
                noResultsMsg.style.display = 'none';
                document.querySelectorAll('.csc-service-item').forEach(i => i.style.display = 'flex');
                sections.forEach(sec => {
                    sec.classList.remove('active');
                    sec.style.display = 'none';
                });
                document.querySelectorAll('.csc-section-tile').forEach(t => t.classList.remove('active'));
            }
        });

        document.querySelectorAll('.csc-section-tile').forEach(tile => {
            tile.addEventListener('click', () => {
                if (searchInput.value !== '') {
                    searchInput.value = '';
                    document.querySelectorAll('.csc-service-item').forEach(i => i.style.display = 'flex');
                }
                const secId = tile.dataset.section;
                const targetSec = document.getElementById('section-' + secId);
                const isActive = tile.classList.contains('active');
                document.querySelectorAll('.csc-section-tile').forEach(t => t.classList.remove('active'));
                document.querySelectorAll('.csc-services-section').forEach(s => {
                    s.classList.remove('active');
                    s.style.display = 'none';
                });
                if (!isActive) {
                    tile.classList.add('active');
                    targetSec.classList.add('active');
                    targetSec.style.display = 'block';
                }
            });
        });

        document.querySelectorAll('.csc-add-initial-btn').forEach(btn => {
            btn.addEventListener('click', function() {
                const wrapper = this.closest('.csc-qty-wrapper');
                const control = wrapper.querySelector('.csc-qty-control');
                const input = control.querySelector('.csc-quantity-input');
                this.style.display = 'none';
                control.classList.add('active');
                input.value = 1;
                input.dispatchEvent(new Event('change'));
            });
        });

        document.querySelectorAll('.csc-qty-btn.csc-plus').forEach(btn => {
            btn.addEventListener('click', function() {
                const wrapper = this.closest('.csc-qty-control');
                const input = wrapper.querySelector('.csc-quantity-input');
                let val = parseInt(input.value) || 0;
                input.value = val + 1;
                input.dispatchEvent(new Event('change'));
            });
        });

        document.querySelectorAll('.csc-qty-btn.csc-minus').forEach(btn => {
            btn.addEventListener('click', function() {
                const control = this.closest('.csc-qty-control');
                const wrapper = control.closest('.csc-qty-wrapper');
                const addBtn = wrapper.querySelector('.csc-add-initial-btn');
                const input = control.querySelector('.csc-quantity-input');
                let val = parseInt(input.value) || 0;
                if (val <= 1) {
                    input.value = 0;
                    control.classList.remove('active');
                    addBtn.style.display = 'flex';
                } else {
                    input.value = val - 1;
                }
                input.dispatchEvent(new Event('change'));
            });
        });

        document.querySelectorAll('.csc-quantity-input').forEach(input => {
            input.addEventListener('change', () => {
                updateSummary();
                if (isPromoActive) checkServerDiscounts();
                resetDateTime();
            });
        });

        if (isPromoActive) {
            const promoBtn = document.getElementById('csc-apply-promo');
            if (promoBtn) {
                promoBtn.addEventListener('click', checkServerDiscounts);
            }
            const phoneField = document.getElementById('csc-phone');
            if (phoneField) {
                phoneField.addEventListener('blur', checkServerDiscounts);
            }
        }

        const dateInput = document.getElementById('csc-date');
        if (dateInput) {
            dateInput.addEventListener('change', function() {
                const date = this.value;
                if (!date) return;
                if (totalDuration === 0) {
                    alert('Сначала выберите услуги!');
                    this.value = '';
                    return;
                }
                fetch('<?php echo admin_url("admin-ajax.php"); ?>', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                    body: 'action=csc_get_available_slots&date=' + encodeURIComponent(date) + '&duration=' + totalDuration
                })
                .then(r => r.json())
                .then(res => {
                    const timeSelect = document.getElementById('csc-time');
                    timeSelect.innerHTML = '<option value="">Выберите время</option>';
                    if (res.success && Array.isArray(res.data)) {
                        res.data.forEach(slot => {
                            const opt = document.createElement('option');
                            opt.value = slot;
                            opt.textContent = slot;
                            timeSelect.appendChild(opt);
                        });
                    }
                })
                .catch(err => {
                    console.error('Slots fetch error:', err);
                    showNotice('Не удалось загрузить время', 'error');
                });
            });
        }

        // ============================================================
        // ОТПРАВКА ЗАЯВКИ С ВИЗУАЛЬНЫМ ПРОГРЕСС-БАРОМ
        // ============================================================
        document.getElementById('csc-final-form').addEventListener('submit', function(e) {
            e.preventDefault();
            const btn = this.querySelector('.csc-submit-btn');
            if (btn.disabled) return;

            // === ДОБАВЛЕНО ИЗ ВЕРСИИ 2: проверка выбранного времени для кастомного селекта ===
            const timeSelect = document.getElementById('csc-time');
            if (timeSelect && timeSelect.dataset.cscRequired === '1' && !timeSelect.value) {
                showNotice('Выберите время', 'error');
                const wrap = timeSelect.closest('.csc-select-wrap');
                if (wrap) {
                    const trigger = wrap.querySelector('.csc-select-trigger');
                    if (trigger) trigger.focus();
                }
                return;
            }

            if (currentFinalTotal > 0 && currentFinalTotal < minOrderAmount) {
                showNotice(`Минимальная сумма заказа: ${minOrderAmount} ₽`, 'error');
                return;
            }

            if (!this.agree_privacy.checked || !this.agree_rules.checked) {
                showNotice('Необходимо принять условия сервиса', 'error');
                return;
            }

            if (totalDuration === 0) {
                showNotice('Выберите хотя бы одну услугу', 'error');
                return;
            }

            const hasAnyService = Array.from(document.querySelectorAll('.csc-quantity-input')).some(input => parseInt(input.value) > 0);
            if (!hasAnyService) {
                showNotice('Выберите хотя бы одну услугу', 'error');
                return;
            }

            btn.disabled = true;
            btn.textContent = 'Отправка...';

            const servicesData = [];
            document.querySelectorAll('.csc-quantity-input').forEach(input => {
                const qty = parseInt(input.value) || 0;
                if (qty > 0) {
                    servicesData.push({
                        key: input.name.replace('quantity_', ''),
                        qty: qty
                    });
                }
            });

            const servicesBase64 = btoa(JSON.stringify(servicesData));
            const formData = new FormData(this);
            formData.append('action', 'csc_submit_form');
            formData.append('services_base64', servicesBase64);

            if (isPromoActive) {
                const promoInput = document.getElementById('csc-promo-code');
                if (promoInput) {
                    formData.append('promo_code', promoInput.value);
                }
            }

            cscShowProgress();
            cscProgressStepCompleted(1);
            cscProgressStepActive(2);

            fetch('<?php echo admin_url("admin-ajax.php"); ?>', {
                method: 'POST',
                body: formData
            })
            .then(r => {
                cscProgressStepCompleted(2);
                cscProgressStepActive(3);
                if (!r.ok) throw new Error('HTTP ' + r.status);
                return r.json();
            })
            .then(res => {
                btn.disabled = false;
                btn.textContent = 'Отправить заявку';
                if (res.success) {
                    cscProgressStepCompleted(3);
                    cscProgressStepActive(4);
                    setTimeout(() => {
                        cscProgressStepCompleted(4);
                        cscProgressSuccess();
                    }, 300);

                    if (res.data.redirect_url) {
                        window.location.href = res.data.redirect_url;
                        return;
                    }
                    document.getElementById('csc-form-container').style.display = 'none';
                    const resultScreen = document.getElementById('csc-result-screen');
                    document.getElementById('csc-receipt-number-display').textContent = 'Номер заявки: ' + res.data.order_number;
                    if (res.data.receipt_data) {
                        document.getElementById('csc-receipt-details').innerHTML = res.data.receipt_data;
                    }
                    resultScreen.style.display = 'block';
                    resultScreen.scrollIntoView({ behavior: "smooth" });
                } else {
                    const errMsg = res.data?.message || 'Не удалось отправить заявку';
                    cscProgressError(3, errMsg);
                    showNotice('❌ Ошибка: ' + errMsg, 'error');
                }
            })
            .catch(err => {
                btn.disabled = false;
                btn.textContent = 'Отправить заявку';
                cscProgressError(3, 'Ошибка сети. Проверьте подключение и попробуйте снова.');
                showNotice('❌ Ошибка сети. Попробуйте позже.', 'error');
                console.error(err);
            });
        });
    }
	    function resetDateTime() {
        const dateInput = document.getElementById('csc-date');
        const timeSelect = document.getElementById('csc-time');
        if (dateInput.value) {
            dateInput.value = '';
            timeSelect.innerHTML = '<option value="">Выберите дату</option>';
            showNotice('Время сброшено, так как изменился состав услуг. Выберите время заново.', 'info');
        }
    }

    function checkMinOrderWarning() {
        const warningEl = document.getElementById('csc-min-order-warning');
        if (!warningEl) return;
        if (currentFinalTotal > 0 && currentFinalTotal < minOrderAmount) {
            warningEl.style.display = 'block';
        } else {
            warningEl.style.display = 'none';
        }
    }

    function updateSummary() {
        let total = 0;
        let duration = 0;
        document.querySelectorAll('.csc-quantity-input').forEach(input => {
            const qty = parseInt(input.value) || 0;
            total += qty * (parseFloat(input.dataset.price) || 0);
            duration += qty * (parseInt(input.dataset.duration) || 0);
        });
        totalDuration = duration;
        totalPriceBase = total;
        currentFinalTotal = total;
        document.getElementById('csc-base-total').textContent = total.toLocaleString() + ' ₽';
        document.getElementById('csc-total').textContent = total.toLocaleString() + ' ₽';
        const h = Math.floor(duration / 60);
        const m = duration % 60;
        document.getElementById('csc-duration').textContent = h + ' ч ' + m + ' мин';
        checkMinOrderWarning();
        const dateInput = document.getElementById('csc-date');
        if (dateInput) {
            if (totalDuration > 0) {
                dateInput.disabled = false;
            } else {
                dateInput.disabled = true;
                dateInput.value = '';
            }
        }
    }

    function checkServerDiscounts() {
        if (!isPromoActive) return;
        const services = [];
        document.querySelectorAll('.csc-quantity-input').forEach(i => {
            if (i.value > 0) {
                services.push({ key: i.name.replace('quantity_', ''), qty: i.value });
            }
        });
        if (services.length === 0) {
            const discBlock = document.getElementById('csc-discounts-block');
            if (discBlock) discBlock.innerHTML = '';
            currentFinalTotal = 0;
            document.getElementById('csc-total').textContent = '0 ₽';
            checkMinOrderWarning();
            return;
        }
        const phone = document.getElementById('csc-phone').value;
        const promoInput = document.getElementById('csc-promo-code');
        let code = promoInput ? promoInput.value : '';
        code = code.trim().toUpperCase();
        fetch('<?php echo admin_url("admin-ajax.php"); ?>', {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: `action=csc_check_promo_calc&services_base64=${btoa(JSON.stringify(services))}&phone=${encodeURIComponent(phone)}&code=${encodeURIComponent(code)}`
        })
        .then(r => r.json())
        .then(res => {
            if (res.success) {
                const d = res.data;
                currentFinalTotal = d.total;
                document.getElementById('csc-total').textContent = currentFinalTotal.toLocaleString() + ' ₽';
                checkMinOrderWarning();
                const discBlock = document.getElementById('csc-discounts-block');
                if (discBlock) {
                    if (d.discount > 0) {
                        discBlock.innerHTML = `<div class="csc-discount-row"><span>Скидка (${d.info}):</span> <strong>-${d.discount} ₽</strong></div>`;
                    } else {
                        discBlock.innerHTML = '';
                    }
                }
            }
        })
        .catch(err => console.error(err));
    }

    loadForm();
});

function cscCheckFiles(input, maxFiles, maxMb) {
    if (input.files.length > maxFiles) {
        alert(`Максимум ${maxFiles} файлов.`);
        input.value = '';
        return;
    }
    if (maxMb > 0) {
        for (let i = 0; i < input.files.length; i++) {
            if (input.files[i].size > maxMb * 1024 * 1024) {
                alert(`Файл ${input.files[i].name} превышает ${maxMb} Мб.`);
                input.value = '';
                return;
            }
        }
    }
}

function cscUpdatePreview(input, previewId) {
    const previewDiv = document.getElementById(previewId);
    if (input.files && input.files.length > 0) {
        let text = 'Выбрано: ' + input.files.length + ' файл(а)';
        if (input.files.length === 1) text = 'Файл: ' + input.files[0].name;
        previewDiv.textContent = text;
        previewDiv.style.color = 'var(--csc-success)';
        previewDiv.style.fontWeight = '600';
    } else {
        previewDiv.textContent = '';
    }
}

function cscDownloadReceipt() {
    const node = document.getElementById('csc-receipt-node');
    const currentBg = node.style.backgroundColor;
    const currentColor = node.style.color;
    node.style.backgroundColor = '#ffffff';
    node.style.color = '#1e293b';
    html2canvas(node, {
        scale: 2,
        backgroundColor: "#ffffff"
    }).then(canvas => {
        node.style.backgroundColor = currentBg;
        node.style.color = currentColor;
        const link = document.createElement('a');
        link.download = 'Receipt-' + new Date().getTime() + '.png';
        link.href = canvas.toDataURL();
        link.click();
    }).catch(err => {
        console.error(err);
        alert('Не удалось сформировать изображение. Попробуйте сделать скриншот.');
        node.style.backgroundColor = currentBg;
        node.style.color = currentColor;
    });
}

// ============================================================
// ПОДСКАЗКИ АДРЕСА ЧЕРЕЗ DADATA (ОПЦИОНАЛЬНО)
// ============================================================
const DADATA_API_TOKEN = '<?php echo esc_js(get_option("csc_dadata_api_key", "bd66fd52de6dad670372e3eabe0c1b7d1a92a1f3")); ?>';

function initAddressSuggestions() {
    const addressInput = document.getElementById('csc-address-input');
    if (!addressInput || !DADATA_API_TOKEN) return;

    const suggestionsBox = document.createElement('div');
    suggestionsBox.style.cssText = 'position:absolute; z-index:10000; background:var(--csc-card); border:1px solid var(--csc-border); border-radius:8px; box-shadow:0 4px 12px rgba(0,0,0,0.15); max-height:200px; overflow-y:auto; width:100%; display:none;';
    addressInput.parentElement.style.position = 'relative';
    addressInput.parentElement.appendChild(suggestionsBox);

    let debounceTimer = null;

    addressInput.addEventListener('input', function() {
        clearTimeout(debounceTimer);
        const query = this.value.trim();

        if (query.length < 3) {
            suggestionsBox.style.display = 'none';
            return;
        }

        debounceTimer = setTimeout(() => {
            fetch('https://suggestions.dadata.ru/suggestions/api/4_1/rs/suggest/address', {
                method: 'POST',
                headers: {
                    'Authorization': 'Token ' + DADATA_API_TOKEN,
                    'Content-Type': 'application/json',
                },
                body: JSON.stringify({ query: query, count: 5 })
            })
            .then(r => r.json())
            .then(data => {
                const suggestions = data.suggestions || [];
                if (suggestions.length === 0) {
                    suggestionsBox.style.display = 'none';
                    return;
                }

                suggestionsBox.innerHTML = suggestions.map(s =>
                    '<div class="csc-address-suggestion" data-value="' + s.value.replace(/"/g, '&quot;') + '" style="padding:10px 14px; cursor:pointer; border-bottom:1px solid var(--csc-border); font-size:14px;">' + s.value + '</div>'
                ).join('');
                suggestionsBox.style.display = 'block';

                suggestionsBox.querySelectorAll('.csc-address-suggestion').forEach(item => {
                    item.addEventListener('click', function() {
                        addressInput.value = this.dataset.value;
                        suggestionsBox.style.display = 'none';
                    });
                });
            })
            .catch(err => {
                suggestionsBox.style.display = 'none';
            });
        }, 300);
    });

    document.addEventListener('click', function(e) {
        if (e.target !== addressInput && !suggestionsBox.contains(e.target)) {
            suggestionsBox.style.display = 'none';
        }
    });
}

const observer = new MutationObserver(() => {
    if (document.getElementById('csc-address-input')) {
        initAddressSuggestions();
        observer.disconnect();
    }
});
observer.observe(document.getElementById('csc-form-container'), { childList: true, subtree: true });

// ============================================================
// ДОБАВЛЕНО ИЗ ВЕРСИИ 2: КОМПАКТНЫЙ ВЫПАДАЮЩИЙ СПИСОК СЛОТОВ ВРЕМЕНИ
// Улучшает нативный <select id="csc-time"> без потери FormData
// ============================================================
(function () {
    const root = document.getElementById('csc-calculator');
    if (!root) return;

    const TIME_RE = /^([01]?\d|2[0-3]):[0-5]\d$/;

    function debounce(fn, ms) {
        let t;
        return function () {
            clearTimeout(t);
            t = setTimeout(fn, ms);
        };
    }

    function looksLikeTimeSelect(sel) {
        if (!sel || sel.dataset.cscTimeEnhanced === '1') return false;

        const meta = `${sel.id} ${sel.className} ${sel.name || ''}`.toLowerCase();
        if (/time|slot|слот|время/.test(meta)) return true;

        const opts = Array.from(sel.options || []);
        const real = opts.filter(o => o.value !== '');
        if (!real.length) return false;

        const timeCount = real.filter(o =>
            TIME_RE.test(o.value) || TIME_RE.test((o.textContent || '').trim())
        ).length;

        return timeCount / real.length >= 0.5;
    }

    function closeAll(except) {
        document.querySelectorAll('#csc-calculator .csc-select-wrap.open').forEach(w => {
            if (w === except) return;
            w.classList.remove('open');
            const tr = w.querySelector('.csc-select-trigger');
            if (tr) tr.setAttribute('aria-expanded', 'false');
        });
    }

    function enhanceSelect(sel) {
        if (!sel.parentNode || sel.dataset.cscTimeEnhanced === '1') return;
        sel.dataset.cscTimeEnhanced = '1';

        if (sel.required) {
            sel.dataset.cscRequired = '1';
            sel.required = false;
            sel.removeAttribute('required');
        }

        const wrap = document.createElement('div');
        wrap.className = 'csc-select-wrap';
        sel.parentNode.insertBefore(wrap, sel);
        wrap.appendChild(sel);

        sel.classList.add('csc-native-select-hidden');
        sel.setAttribute('tabindex', '-1');

        const trigger = document.createElement('button');
        trigger.type = 'button';
        trigger.className = 'csc-select-trigger';
        trigger.setAttribute('aria-haspopup', 'listbox');
        trigger.setAttribute('aria-expanded', 'false');
        trigger.innerHTML = '<span class="csc-select-value"></span><span class="csc-select-arrow"></span>';

        const panel = document.createElement('div');
        panel.className = 'csc-select-panel';
        panel.setAttribute('role', 'listbox');

        const list = document.createElement('ul');
        list.className = 'csc-select-options';
        panel.appendChild(list);

        wrap.appendChild(trigger);
        wrap.appendChild(panel);

        const valueEl = trigger.querySelector('.csc-select-value');

        function positionPanel() {
            panel.style.top = 'calc(100% + 8px)';
            panel.style.bottom = 'auto';

            requestAnimationFrame(() => {
                const rect = panel.getBoundingClientRect();
                if (rect.bottom > window.innerHeight - 8 && rect.top > window.innerHeight - rect.height) {
                    panel.style.top = 'auto';
                    panel.style.bottom = 'calc(100% + 8px)';
                }
            });
        }

        function refresh() {
            if (!sel.isConnected) {
                selObserver.disconnect();
                if (wrap.isConnected) wrap.remove();
                return;
            }

            list.innerHTML = '';
            let selectedText = '';
            const selectedIndex = Number(sel.selectedIndex);

            Array.from(sel.options).forEach((opt, idx) => {
                const li = document.createElement('li');
                li.className = 'csc-select-option';
                li.dataset.index = String(idx);
                li.setAttribute('role', 'option');

                const text = (opt.textContent || '').trim();
                const isPlaceholder =
                    !opt.value ||
                    (!TIME_RE.test(opt.value) && !TIME_RE.test(text) && /выбери|выберете|время|дата|—|-/i.test(text));

                if (isPlaceholder) {
                    li.classList.add('placeholder');
                    li.textContent = text || 'Выберите время';
                    li.setAttribute('aria-disabled', 'true');
                } else {
                    li.textContent = text || opt.value;
                    if (opt.disabled) {
                        li.classList.add('disabled');
                        li.setAttribute('aria-disabled', 'true');
                    } else {
                        li.tabIndex = 0;
                    }
                }

                if (selectedIndex === idx) {
                    li.classList.add('selected');
                    li.setAttribute('aria-selected', 'true');
                    selectedText = li.textContent;
                }

                list.appendChild(li);
            });

            valueEl.textContent = selectedText || 'Выберите время';
        }

        function open() {
            closeAll(wrap);
            wrap.classList.add('open');
            trigger.setAttribute('aria-expanded', 'true');
            positionPanel();
        }

        function close() {
            wrap.classList.remove('open');
            trigger.setAttribute('aria-expanded', 'false');
        }

        function toggle() {
            wrap.classList.contains('open') ? close() : open();
        }

        function selectIndex(idx) {
            const opt = sel.options[idx];
            if (!opt || opt.disabled || !opt.value) return;

            sel.selectedIndex = idx;
            sel.dispatchEvent(new Event('change', { bubbles: true }));
            sel.dispatchEvent(new Event('input', { bubbles: true }));

            refresh();
            close();
        }

        trigger.addEventListener('click', (e) => {
            e.preventDefault();
            e.stopPropagation();
            toggle();
        });

        trigger.addEventListener('keydown', (e) => {
            if (e.key === 'ArrowDown' || e.key === 'Enter' || e.key === ' ') {
                e.preventDefault();
                open();
                const first =
                    list.querySelector('.csc-select-option.selected:not(.placeholder):not(.disabled)') ||
                    list.querySelector('.csc-select-option:not(.placeholder):not(.disabled)');
                if (first) first.focus();
            }
        });

        list.addEventListener('click', (e) => {
            const li = e.target.closest('.csc-select-option');
            if (!li || li.classList.contains('placeholder') || li.classList.contains('disabled')) return;

            e.stopPropagation();
            selectIndex(Number(li.dataset.index));
        });

        list.addEventListener('keydown', (e) => {
            const current = e.target.closest('.csc-select-option');
            if (!current) return;

            if (e.key === 'Enter' || e.key === ' ') {
                e.preventDefault();
                selectIndex(Number(current.dataset.index));
                trigger.focus();
                return;
            }

            if (e.key === 'ArrowDown' || e.key === 'ArrowUp') {
                e.preventDefault();
                const items = Array.from(list.querySelectorAll('.csc-select-option:not(.placeholder):not(.disabled)'));
                const i = items.indexOf(current);
                const next = e.key === 'ArrowDown' ? items[i + 1] : items[i - 1];
                if (next) next.focus();
            }

            if (e.key === 'Escape') {
                close();
                trigger.focus();
            }
        });

        const selObserver = new MutationObserver(debounce(refresh, 80));
        selObserver.observe(sel, { childList: true, subtree: true, characterData: true });

        sel.addEventListener('csc-refresh-time-select', refresh);
        refresh();
    }

    function scan() {
        root.querySelectorAll('select').forEach(sel => {
            if (looksLikeTimeSelect(sel)) enhanceSelect(sel);
        });
    }

    scan();

    const rootObserver = new MutationObserver(debounce(scan, 120));
    rootObserver.observe(root, { childList: true, subtree: true });

    document.addEventListener('click', (e) => {
        closeAll(e.target.closest ? e.target.closest('.csc-select-wrap') : null);
    });

    document.addEventListener('keydown', (e) => {
        if (e.key === 'Escape') closeAll();
    });
})();
</script>
<?php
return ob_get_clean();
}

// Вызов add_shortcode ОБЯЗАТЕЛЬНО должен быть за пределами проверки if(!function_exists)
add_shortcode('service_calculator', 'csc_calculator_shortcode');