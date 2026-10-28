<?php
/**
* Файл: csc-masters.php
* Описание: CRUD мастеров, поиск свободного мастера, админ-страница сотрудников.
* Версия: 6.2 (объединение 6.1 + восстановление функционала 3.6)
*
* ИСПРАВЛЕНО В 6.2:
* - Восстановлено детальное расписание (schedule) в csc_find_free_master (из 3.6)
* - Восстановлена отправка email мастеру при модерации (из 3.6)
* - Восстановлены внутрикабинетные уведомления csc_add_master_notification (из 3.6)
* - Восстановлен маппинг зон ID→названия через csc_get_city_zones (из 3.6)
* - Восстановлено склонение опыта работы через _n() (из 3.6)
* - Восстановлена полноценная сборка документов photo+document_photo+documents (из 3.6)
* - Восстановлены поля city, city_name, schedule в JSON-ответе (из 3.6)
*
* СОХРАНЕНО ИЗ 6.1:
* - Критическое исправление часовых поясов (DateTime + wp_timezone)
* - Проверка work_days (дни недели) в csc_find_free_master
* - DateTime в сортировке заказов csc_get_master_details_ajax
* - Карта Leaflet с invalidateSize при переключении вкладок
* - Проверка response.ok во всех fetch-запросах
* - Проверка typeof L === 'undefined' перед инициализацией карты
* - SQL-оптимизация статистики при миграции в БД
*/
if (!defined('ABSPATH')) exit;

// ============================================================
// 0. ВСПОМОГАТЕЛЬНЫЕ ФУНКЦИИ
// ============================================================

/**
* Очистка и унификация номера телефона.
* Приводит любой формат (+7 (999) 123-45-67, 89991234567) к 79991234567.
* Примечание: отличается от csc_normalize_phone в core-functions тем,
* что возвращает строку без префикса для внутренних нужд.
*/
if (!function_exists('csc_clean_phone')) {
    function csc_clean_phone($phone) {
        if (empty($phone)) return '';
        $clean = preg_replace('/[^0-9]/', '', $phone);
        if (strlen($clean) === 11 && strpos($clean, '8') === 0) {
            $clean = '7' . substr($clean, 1);
        } elseif (strlen($clean) === 10) {
            $clean = '7' . $clean;
        }
        return $clean;
    }
}

// ============================================================
// 1. УПРАВЛЕНИЕ ДАННЫМИ МАСТЕРОВ (CRUD)
// ============================================================

/**
* Получить список всех мастеров.
* Кэшируется через wp_cache с единым ключом csc_masters_list_v2.
* Если список пуст, создаётся дефолтный мастер для обратной совместимости.
*/
if (!function_exists('csc_get_masters')) {
    function csc_get_masters($force_refresh = false) {
        $cache_key = 'csc_masters_list_v2'; // Единый ключ с csc-order-routing.php
        $masters = wp_cache_get($cache_key);
        if (!$force_refresh && false !== $masters) {
            return $masters;
        }
        $masters = get_option('csc_masters_list', []);

        // Если это первый запуск или список пуст — создаём "Основного мастера"
        if (empty($masters)) {
            $default_id = 'master_default';
            $masters[$default_id] = [
                'id'              => $default_id,
                'name'            => 'Основной мастер',
                'phone'           => '',
                'tg_chat_id'      => '',
                'color'           => '#3b82f6',
                'comm_type'       => 'percent',
                'comm_val'        => 30,
                'active'          => true,
                // В хранилище — только хэш постоянного токена (SHA-256)
                'dashboard_token' => csc_hash_token(wp_generate_password(32, false))
            ];
            update_option('csc_masters_list', $masters, false);
        }

        // Гарантируем наличие dashboard_token + миграция модерационного статуса
        $need_save = false;
        foreach ($masters as $mid => &$mdata) {
            // Токен дашборда: при отсутствии генерируем на сервере, храним только хэш.
            if (empty($mdata['dashboard_token']) || strlen($mdata['dashboard_token']) < 16) {
                $mdata['dashboard_token'] = csc_hash_token(wp_generate_password(32, false));
                $need_save = true;
            }

            // === Модерационный статус ===
            // Для существующих мастеров (созданных до внедрения модерации) —
            // автоматически присваиваем статус 'active'
            if (!isset($mdata['status']) || empty($mdata['status'])) {
                $mdata['status'] = 'active';
                $mdata['is_online'] = !empty($mdata['active']); // синхронизация со старым полем
                $mdata['approved_at'] = $mdata['created_at'] ?? current_time('mysql');
                $need_save = true;
            }
        }
        unset($mdata);

        if ($need_save) {
            update_option('csc_masters_list', $masters, false);
        }

        wp_cache_set($cache_key, $masters, '', HOUR_IN_SECONDS);
        return $masters;
    }
}

/**
* Сохранить список мастеров.
* Вызывает csc_cache_delete_masters() после сохранения для сброса кэша.
*/
if (!function_exists('csc_save_masters')) {
    function csc_save_masters($masters) {
        foreach ($masters as &$master) {
            // Нормализация часов работы
            if (isset($master['work_hours_start']) && isset($master['work_hours_end'])) {
                $start = intval($master['work_hours_start']);
                $end = intval($master['work_hours_end']);
                $start = max(0, min(23, $start));
                $end = max(0, min(23, $end));
                if ($end <= $start) {
                    $end = $start + 1;
                    if ($end > 23) $end = 23;
                }
                $master['work_hours_start'] = $start;
                $master['work_hours_end'] = $end;
            }

            // Гарантируем наличие dashboard_token (в хранилище — только хэш)
            if (empty($master['dashboard_token']) || strlen($master['dashboard_token']) < 16) {
                $master['dashboard_token'] = csc_hash_token(wp_generate_password(32, false));
            }
        }
        unset($master);

        update_option('csc_masters_list', $masters, false);

        // Сброс кэшей мастеров после сохранения
        if (function_exists('csc_cache_delete_masters')) {
            csc_cache_delete_masters();
        } else {
            wp_cache_delete('csc_masters_list_v2');
            wp_cache_delete('csc_masters_tg_v2');
            wp_cache_delete('csc_masters_ratings_v2');
        }
    }
}

// ============================================================
// 2. ЛОГИКА РАСПРЕДЕЛЕНИЯ (ПОИСК СВОБОДНОГО)
// ============================================================

/**
* Ищет первого свободного мастера на конкретную дату и время.
* Учитывает: длительность услуги + время на дорогу + индивидуальные часы работы,
* дни недели (work_days) + детальное расписание (schedule).
*
* ИСПРАВЛЕНО 6.1/6.2: DateTime + wp_timezone() для корректного часового пояса.
* ВОССТАНОВЛЕНО 6.2: детальное расписание schedule (из 3.6).
*/
if (!function_exists('csc_find_free_master')) {
    function csc_find_free_master($date, $time_start, $duration_minutes, $client_lat = null, $client_lng = null) {
        $masters = csc_get_masters();
        $active_masters = array_filter($masters, function($m) {
            // Базовая активность (старое поле, backward compatibility)
            if (empty($m['active'])) return false;

            // Модерационный статус должен быть 'active'
            $status = $m['status'] ?? 'active';
            if ($status !== 'active') return false;

            // Онлайн-статус (переключатель в дашборде)
            // По умолчанию считаем онлайн=true для старых мастеров
            $is_online = isset($m['is_online']) ? !empty($m['is_online']) : true;
            if (!$is_online) return false;

            return true;
        });
        if (empty($active_masters)) {
            return false;
        }

        // ИСПРАВЛЕНО 6.1: Используем DateTime с часовым поясом WordPress
        $tz = wp_timezone();
        try {
            $dt_start = new DateTime("$date $time_start", $tz);
            $req_start_ts = $dt_start->getTimestamp();
        } catch (Exception $e) {
            return false;
        }

        $s = get_option('csc_main_settings', []);
        $travel_time = intval($s['travel_time'] ?? 0);

        // Учитываем время на дорогу для самого нового заказа, чтобы мастер успел доехать
        $req_end_ts = $req_start_ts + (($duration_minutes + $travel_time) * 60);

        // Единая функция чтения заказов
        $all_orders = function_exists('csc_get_all_orders') ? csc_get_all_orders() : get_option('csc_all_orders', []);

        // ИСПРАВЛЕНО 6.1: День недели через DateTime (корректный TZ)
        $req_day_of_week = (int)$dt_start->format('N'); // 1=Пн ... 7=Вс (ISO-8601)

        foreach ($active_masters as $m_id => $m_data) {
            $is_busy = false;

            // === ПРОВЕРКА ДНЯ НЕДЕЛИ (work_days) ===
            if (!empty($m_data['work_days']) && is_array($m_data['work_days'])) {
                $work_days_int = array_map('intval', $m_data['work_days']);
                if (!in_array($req_day_of_week, $work_days_int)) {
                    continue; // Мастер не работает в этот день недели
                }
            }

            // === ИСПРАВЛЕНО 6.2.1: ПРОВЕРКА ДЕТАЛЬНОГО РАСПИСАНИЯ (schedule) ===
            $slot_hour = (int)$dt_start->format('H');
            $slot_minute = (int)$dt_start->format('i');
            $slot_decimal = $slot_hour + ($slot_minute / 60);
            $day_api_keys = [1=>'monday', 2=>'tuesday', 3=>'wednesday', 4=>'thursday', 5=>'friday', 6=>'saturday', 7=>'sunday'];
            $schedule_checked = false;

            if (isset($m_data['schedule']) && is_array($m_data['schedule'])) {
                $day_key = $day_api_keys[$req_day_of_week] ?? '';
                $day_schedule = $m_data['schedule'][$day_key] ?? null;

                if ($day_schedule !== null) {
                    if (empty($day_schedule['enabled'])) {
                        continue; // День выключен в расписании мастера
                    }
                    $from_parts = explode(':', $day_schedule['from'] ?? '09:00');
                    $to_parts = explode(':', $day_schedule['to'] ?? '18:00');
                    
                    // ИСПРАВЛЕНО 6.2: десятичные часы с учётом минут
                    $from_decimal = intval($from_parts[0]) + (intval($from_parts[1] ?? 0) / 60);
                    $to_decimal = intval($to_parts[0]) + (intval($to_parts[1] ?? 0) / 60);
                    
                    if ($slot_decimal < $from_decimal || $slot_decimal >= $to_decimal) {
                        continue; // Мастер не работает в этот час по детальному расписанию
                    }
                    
                    // Проверяем, что заказ закончится до конца смены мастера
                    $req_end_decimal = $slot_decimal + (($duration_minutes + $travel_time) / 60);
                    if ($req_end_decimal > $to_decimal) {
                        continue;
                    }
                    
                    $schedule_checked = true; // Детальное расписание проверено
                }
                // ИСПРАВЛЕНО 6.2.1: Если $day_schedule === null (день не указан в расписании),
                // не переходим к work_hours — считаем мастера свободным в этот день.
                // Это корректное поведение: расписание не ограничивает этот день.
            }

            // Проверка индивидуальных часов работы мастера (фолбэк если нет детального расписания)
            if (!$schedule_checked && isset($m_data['work_hours_start']) && isset($m_data['work_hours_end'])) {
                $m_start_h = intval($m_data['work_hours_start']);
                $m_end_h = intval($m_data['work_hours_end']);

                // Если мастер не работает в этот час — пропускаем
                if ($slot_hour < $m_start_h || $slot_hour >= $m_end_h) {
                    continue;
                }

                // Проверяем, успеваем ли закончить до конца рабочего дня мастера
                // ИСПРАВЛЕНО 6.1: DateTime с TZ вместо strtotime
                try {
                    $dt_end_work = new DateTime("$date " . str_pad($m_end_h, 2, '0', STR_PAD_LEFT) . ":00:00", $tz);
                    $m_end_work_ts = $dt_end_work->getTimestamp();
                    if ($req_end_ts > $m_end_work_ts) {
                        continue;
                    }
                } catch (Exception $e) {
                    continue;
                }
            }

            // === ИСПРАВЛЕНО 6.3: ПРОВЕРКА РАДИУСА ОБСЛУЖИВАНИЯ ===
            // Если заказ имеет гео-координаты и мастер указал точку выезда,
            // проверяем расстояние. Мастер вне радиуса не получает заказ.
            if (!empty($client_lat) && !empty($client_lng)
                && !empty($m_data['service_lat']) && !empty($m_data['service_lng'])
                && function_exists('csc_haversine_km')) {
                $distance_km = csc_haversine_km(
                    $client_lat, $client_lng,
                    $m_data['service_lat'], $m_data['service_lng']
                );
                $master_radius = max(1, intval($m_data['service_radius'] ?? 10));
                if ($distance_km > $master_radius) {
                    continue; // Мастер вне зоны обслуживания
                }
            }
            // === КОНЕЦ ПРОВЕРКИ РАДИУСА ===

            foreach ($all_orders as $order) {
                // Исключаем отменённые и завершённые заказы
                $ignored_statuses = array(CSC_STATUS_CANCELLED, CSC_STATUS_COMPLETED, 'rejected');
                if (in_array($order['status'] ?? '', $ignored_statuses)) continue;

                if (($order['date'] ?? '') !== $date) continue;

                // Проверяем и мастера, и текущего кандидата
                if (($order['master_id'] ?? '') !== $m_id && ($order['current_candidate'] ?? '') !== $m_id) continue;

                // ИСПРАВЛЕНО 6.1: DateTime для парсинга времени заказа
                $order_time_str = ($order['date'] ?? '') . ' ' . ($order['time'] ?? '00:00');
                try {
                    $dt_order = new DateTime($order_time_str, $tz);
                    $order_start_ts = $dt_order->getTimestamp();
                } catch (Exception $e) {
                    continue;
                }

                $order_duration = intval($order['duration'] ?? 0) + $travel_time;
                $order_end_ts = $order_start_ts + ($order_duration * 60);

                if ($req_start_ts < $order_end_ts && $req_end_ts > $order_start_ts) {
                    $is_busy = true;
                    break;
                }
            }

            if (!$is_busy) {
                return $m_id;
            }
        }

        return false;
    }
}

// ============================================================
// 3. СТРАНИЦА АДМИНКИ (СОТРУДНИКИ И КОМИССИЯ)
// ============================================================

if (!function_exists('csc_masters_admin_page')) {
    function csc_masters_admin_page() {
        // Обработка сохранения формы
        if ($_POST && isset($_POST['save_masters']) && wp_verify_nonce($_POST['_wpnonce'], 'csc_masters_action')) {
            $new_masters = [];
            if (!empty($_POST['masters'])) {
                foreach ($_POST['masters'] as $m) {
                    if (empty($m['name'])) continue;

                    $id = !empty($m['id']) ? sanitize_text_field($m['id']) : uniqid('master_');
                    $comm_type = sanitize_text_field($m['comm_type'] ?? 'percent');
                    $comm_val  = floatval($m['comm_val'] ?? 0);

                    if ($comm_type === 'percent') {
                        $comm_val = max(0, min(100, $comm_val));
                    } else {
                        $comm_val = max(0, $comm_val);
                    }

                    // Сохранение dashboard_token: из формы принимаем только stored-значение (хэш);
                    // при отсутствии/некорректном значении генерируем новый токен на сервере,
                    // в хранилище сохраняется только его хэш.
                    $dashboard_token = sanitize_text_field($m['dashboard_token'] ?? '');
                    if (csc_is_hashed_token($dashboard_token)) {
                        // уже хэш — ок
                    } elseif (!empty($dashboard_token) && strlen($dashboard_token) >= 16) {
                        // legacy открытое значение из старой формы — мигрируем в хэш
                        $dashboard_token = csc_hash_token($dashboard_token);
                    } else {
                        $dashboard_token = csc_hash_token(wp_generate_password(32, false));
                    }

                    $new_masters[$id] = [
                        'id'              => $id,
                        'name'            => sanitize_text_field($m['name']),
                        'phone'           => csc_clean_phone(sanitize_text_field($m['phone'])),
                        'tg_chat_id'      => sanitize_text_field($m['tg_chat_id']),
                        'color'           => sanitize_hex_color($m['color']) ?: '#3b82f6',
                        'comm_type'       => $comm_type,
                        'comm_val'        => $comm_val,
                        'active'          => !empty($m['active']),
                        'dashboard_token' => $dashboard_token
                    ];
                }
            }

            csc_save_masters($new_masters);
            echo '<div class="notice notice-success"><p>Список сотрудников и финансовые настройки успешно обновлены.</p></div>';
        }

        $masters = csc_get_masters();
        $masters_count = count($masters);

        // === ПОДКЛЮЧЕНИЕ LEAFLET ДЛЯ КАРТЫ ЗОНЫ ОБСЛУЖИВАНИЯ ===
        echo '<link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css" />';
        echo '<script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"></script>';
        ?>
        <div class="wrap">
            <h1>Управление Сотрудниками (Мастерами)</h1>

            <div style="background:#fff; border:1px solid #ccd0d4; padding:20px; border-radius:4px; margin-bottom:20px;">
                <h3>ℹ️ Как это работает</h3>
                <p>
                    1. <strong>Автоматическое распределение:</strong> При заказе система проверяет расписание каждого активного мастера. Заявка назначается первому, кто свободен в это время.<br>
                    2. <strong>Финансы (Комиссия):</strong> Установите долю сервиса с каждого заказа мастера. Она может быть процентом (например, 30%) или фиксированной суммой (например, 500 ₽).<br>
                    3. <strong>Уведомления:</strong> Если у мастера указан <code>Telegram Chat ID</code>, он получит личное уведомление о назначенной заявке.<br>
                    4. <strong>Календарь:</strong> В общем календаре заявки разных мастеров будут помечены выбранными цветами.<br>
                    5. <strong>🔗 Кабинет:</strong> Кнопка в колонке "Действия" открывает ссылку на личный кабинет мастера. Токен сохраняется автоматически.<br>
                    6. <strong>👁️ Просмотр:</strong> Полная карточка мастера с анкетой, документами, статистикой, финансами и историей модерации.
                </p>
                <p>
                    <small><em>* Чтобы узнать Telegram ID, мастер должен написать боту <code>@userinfobot</code> или аналогичному.</em></small>
                </p>
            </div>

            <?php
            // Подсчёт мастеров на модерации
            $pending_count = 0;
            foreach ($masters as $m) {
                if (($m['status'] ?? 'active') === 'pending') $pending_count++;
            }
            ?>

            <?php if ($pending_count > 0): ?>
            <div style="background:#fef3c7; border:1px solid #fbbf24; padding:12px 20px; border-radius:6px; margin-bottom:15px; display:flex; justify-content:space-between; align-items:center; gap:15px;">
                <div>
                    <strong>⏳ На модерации: <?php echo (int) $pending_count; ?> <?php echo esc_html(_n('мастер', 'мастеров', $pending_count, 'csc')); ?></strong>
                    <span style="color:#64748b; margin-left:10px;">Требуется ваше решение</span>
                </div>
                <button type="button" class="button button-primary" onclick="cscBulkApproveMasters()" style="background:#10b981; border-color:#10b981;">
                    ✅ Одобрить всех
                </button>
            </div>
            <?php endif; ?>

            <form method="post">
                <?php wp_nonce_field('csc_masters_action'); ?>
                <table class="wp-list-table widefat fixed striped">
                    <thead>
                        <tr>
                            <th style="width: 16%;">Имя Фамилия</th>
                            <th style="width: 12%;">Телефон</th>
                            <th style="width: 12%;">Telegram ID</th>
                            <th style="width: 16%;">Комиссия сервиса</th>
                            <th style="width: 7%;">Цвет</th>
                            <th style="width: 7%;">Активен</th>
                            <th style="width: 10%;">Статус</th>
                            <th style="width: 20%;">Действия</th>
                        </tr>
                    </thead>
                    <tbody id="masters-rows">
                        <?php
                        if (!empty($masters)) {
                            foreach ($masters as $id => $master) {
                                csc_render_master_table_row($id, $master, $masters_count);
                            }
                        } else {
                            echo '<tr><td colspan="8">Сотрудников нет. Добавьте первого.</td></tr>';
                        }
                        ?>
                    </tbody>
                </table>

                <div style="margin-top: 15px; display: flex; gap: 10px;">
                    <button type="button" class="button button-secondary" onclick="cscAddNewMasterRow()">+ Добавить сотрудника</button>
                    <?php submit_button('Сохранить изменения', 'primary', 'save_masters', false); ?>
                </div>
            </form>

            <script>
            // Модерационные действия
            function cscModerateMaster(masterId, action, masterName) {
                const labels = {
                    'approve': 'Одобрить',
                    'reject': 'Отклонить',
                    'block': 'Заблокировать',
                    'unblock': 'Разблокировать',
                };

                let reason = '';
                if (action === 'reject' || action === 'block') {
                    reason = prompt(`Укажите причину для "${masterName}":`);
                    if (reason === null) return;
                    if (reason.trim() === '') {
                        if (!confirm('Причина не указана. Продолжить?')) return;
                    }
                } else {
                    if (!confirm(`${labels[action]} мастера "${masterName}"?`)) return;
                }

                const fd = new FormData();
                fd.append('action', 'csc_moderate_master');
                fd.append('mid', masterId);
                fd.append('moderation_action', action);
                fd.append('reason', reason);
                fd.append('_wpnonce', '<?php echo wp_create_nonce("csc_masters_action"); ?>');

                fetch('<?php echo esc_url(admin_url("admin-ajax.php")); ?>', {
                    method: 'POST',
                    body: fd,
                })
                .then(r => {
                    if (!r.ok) throw new Error('HTTP error: ' + r.status);
                    return r.json();
                })
                .then(res => {
                    if (res.success) {
                        location.reload();
                    } else {
                        alert('Ошибка: ' + (res.data?.message || 'Неизвестная ошибка'));
                    }
                })
                .catch(err => {
                    alert('Ошибка сети: ' + err.message);
                });
            }

            function cscBulkApproveMasters() {
                if (!confirm('Одобрить ВСЕХ мастеров со статусом "На модерации"?')) return;

                const fd = new FormData();
                fd.append('action', 'csc_bulk_approve_masters');
                fd.append('_wpnonce', '<?php echo wp_create_nonce("csc_masters_action"); ?>');

                fetch('<?php echo esc_url(admin_url("admin-ajax.php")); ?>', {
                    method: 'POST',
                    body: fd,
                })
                .then(r => {
                    if (!r.ok) throw new Error('HTTP error: ' + r.status);
                    return r.json();
                })
                .then(res => {
                    if (res.success) {
                        alert(res.data.message);
                        location.reload();
                    } else {
                        alert('Ошибка: ' + (res.data?.message || 'Неизвестная ошибка'));
                    }
                })
                .catch(err => {
                    alert('Ошибка сети: ' + err.message);
                });
            }
            </script>
        </div>

        <script>
        function cscAddNewMasterRow() {
            const timestamp = Date.now();
            const tbody = document.getElementById('masters-rows');
            const row = document.createElement('tr');

            const newToken = ''; // токен генерируется на сервере (csc_save_master_token)

            row.innerHTML = `
                <td>
                    <input type="hidden" name="masters[${timestamp}][id]" value="">
                    <input type="text" name="masters[${timestamp}][name]" placeholder="Имя мастера" style="width:100%;" required>
                </td>
                <td>
                    <input type="text" name="masters[${timestamp}][phone]" placeholder="+7 (999)..." style="width:100%;">
                </td>
                <td>
                    <input type="text" name="masters[${timestamp}][tg_chat_id]" placeholder="Напр: 123456789" style="width:100%;">
                </td>
                <td>
                    <div style="display:flex; gap:5px; align-items:center;">
                        <input type="number" name="masters[${timestamp}][comm_val]" value="30" style="width:70px;" min="0" step="0.01">
                        <select name="masters[${timestamp}][comm_type]">
                            <option value="percent">%</option>
                            <option value="fixed">₽</option>
                        </select>
                    </div>
                </td>
                <td>
                    <input type="color" name="masters[${timestamp}][color]" value="#3b82f6" style="width:50px; height:30px; padding:0; border:none;">
                </td>
                <td>
                    <label><input type="checkbox" name="masters[${timestamp}][active]" value="1" checked> Активен</label>
                </td>
                <td>
                    <span class="csc-moderation-badge" style="display:inline-block; padding:3px 8px; border-radius:10px; font-size:11px; font-weight:600; background:#d1fae5; color:#065f46;">🟢 Одобрен</span>
                </td>
                <td>
                    <input type="hidden" name="masters[${timestamp}][dashboard_token]" value="${newToken}">
                    <button type="button" class="button button-small csc-view-master-btn" data-id="new_${timestamp}" data-name="Новый мастер" title="Полная карточка мастера">👁️ Просмотр</button>
                    <button type="button" class="button button-link-delete csc-btn-delete">Удалить</button>
                </td>
            `;

            tbody.appendChild(row);
        }
        </script>

        <!-- ============================================================
        МОДАЛЬНОЕ ОКНО: ССЫЛКА НА КАБИНЕТ МАСТЕРА
        ============================================================ -->
        <div id="csc-dash-link-modal" style="display:none; position:fixed; top:0; left:0; width:100%; height:100%; background:rgba(15,23,42,0.5); z-index:99999; align-items:center; justify-content:center;">
            <div style="background:#fff; border-radius:12px; padding:24px; width:90%; max-width:520px; box-shadow:0 20px 40px rgba(0,0,0,0.2);">
                <h3 style="margin:0 0 16px 0; font-size:17px;">🔗 Кабинет мастера: <span id="csc-dash-master-name" style="color:#2563eb;"></span></h3>

                <label style="display:block; font-size:13px; font-weight:600; margin-bottom:6px; color:#64748b;">Ссылка на дашборд:</label>
                <div style="display:flex; gap:8px; margin-bottom:16px;">
                    <input type="text" id="csc-dash-link-input" readonly style="flex:1; padding:10px; border:1px solid #e2e8f0; border-radius:6px; font-size:13px; background:#f8fafc;" onclick="this.select();">
                    <button type="button" class="button button-primary" id="csc-dash-copy-btn">📋 Копировать</button>
                </div>

                <label style="display:block; font-size:13px; font-weight:600; margin-bottom:6px; color:#64748b;">Токен доступа (для отладки):</label>
                <div style="display:flex; gap:8px; margin-bottom:16px;">
                    <input type="text" id="csc-dash-token-input" readonly style="flex:1; padding:10px; border:1px solid #e2e8f0; border-radius:6px; font-size:12px; background:#f8fafc; font-family:monospace;" onclick="this.select();">
                    <button type="button" class="button" id="csc-dash-token-copy-btn">📋</button>
                </div>

                <div style="display:flex; gap:8px; justify-content:space-between; align-items:center;">
                    <button type="button" class="button button-link-delete" id="csc-dash-regen-btn" title="Сгенерировать новый токен. Старая ссылка перестанет работать.">🔄 Сбросить токен</button>
                    <button type="button" class="button" onclick="document.getElementById('csc-dash-link-modal').style.display='none'">Закрыть</button>
                </div>

                <p id="csc-dash-status" style="margin-top:12px; font-size:13px; color:#64748b;"></p>
            </div>
        </div>

        <script>
        let cscCurrentMasterId = '';
        let cscCurrentHiddenInput = null;

        document.body.addEventListener('click', function(e) {
            const btnDash = e.target.closest('.csc-btn-dash');
            if (btnDash) {
                cscShowDashboardLink(btnDash.dataset.id, btnDash.dataset.name, btnDash);
                return;
            }
            const btnMod = e.target.closest('.csc-btn-mod');
            if (btnMod) {
                cscModerateMaster(btnMod.dataset.id, btnMod.dataset.action, btnMod.dataset.name);
                return;
            }
            const btnDel = e.target.closest('.csc-btn-delete');
            if (btnDel) {
                if (confirm('Удалить сотрудника?')) btnDel.closest('tr').remove();
                return;
            }
        });

        function cscShowDashboardLink(masterId, masterName, btnEl) {
            const modal = document.getElementById('csc-dash-link-modal');
            const linkInput = document.getElementById('csc-dash-link-input');
            const tokenInput = document.getElementById('csc-dash-token-input');
            const statusEl = document.getElementById('csc-dash-status');
            const hiddenInput = btnEl.closest('td').querySelector('input[name*="dashboard_token"]');

            cscCurrentMasterId = masterId;
            cscCurrentHiddenInput = hiddenInput;

            document.getElementById('csc-dash-master-name').textContent = masterName;
            statusEl.textContent = '⏳ Получение ссылки...';
            statusEl.style.color = '#64748b';

            // КЛИЕНТСКАЯ ГЕНЕРАЦИЯ ТОКЕНОВ УБРАНА (SHA-256): ссылка и токен выдаются
            // сервером (AJAX csc_save_master_token) — в хранилище только хэш,
            // в URL — одноразовый входной креденшел.
            const fd = new FormData();
            fd.append('action', 'csc_save_master_token');
            fd.append('mid', masterId);
            fd.append('_wpnonce', '<?php echo wp_create_nonce("csc_masters_action"); ?>');

            fetch('<?php echo esc_url(admin_url("admin-ajax.php")); ?>', {
                method: 'POST',
                body: fd
            })
            .then(r => {
                if (!r.ok) throw new Error('HTTP error: ' + r.status);
                return r.json();
            })
            .then(res => {
                if (res.success) {
                    linkInput.value = res.data.link || '';
                    tokenInput.value = '—'; // «живой» токен не отображается: хранится только хэш
                    modal.style.display = 'flex';
                    statusEl.textContent = '✅ Ссылка сгенерирована на сервере. Старые постоянные ссылки больше не используются.';
                    statusEl.style.color = '#10b981';
                } else {
                    modal.style.display = 'flex';
                    statusEl.textContent = '⚠️ ' + (res.data?.message || 'Ошибка генерации ссылки.');
                    statusEl.style.color = '#d97706';
                }
            })
            .catch(err => {
                modal.style.display = 'flex';
                statusEl.textContent = '⚠️ Ошибка сети: ' + err.message;
                statusEl.style.color = '#d97706';
            });
        }

        document.getElementById('csc-dash-copy-btn').addEventListener('click', function() {
            const input = document.getElementById('csc-dash-link-input');
            input.select();
            const statusEl = document.getElementById('csc-dash-status');
            navigator.clipboard.writeText(input.value).then(() => {
                statusEl.textContent = '✅ Ссылка скопирована!';
                statusEl.style.color = '#10b981';
            }).catch(() => {
                document.execCommand('copy');
                statusEl.textContent = '✅ Ссылка скопирована!';
                statusEl.style.color = '#10b981';
            });
        });

        document.getElementById('csc-dash-token-copy-btn').addEventListener('click', function() {
            const input = document.getElementById('csc-dash-token-input');
            input.select();
            navigator.clipboard.writeText(input.value).catch(() => document.execCommand('copy'));
            const statusEl = document.getElementById('csc-dash-status');
            statusEl.textContent = '✅ Токен скопирован';
            statusEl.style.color = '#10b981';
        });

        document.getElementById('csc-dash-regen-btn').addEventListener('click', function() {
            if (!confirm('Сбросить токен? Старая ссылка на кабинет перестанет работать.')) return;

            const tokenInput = document.getElementById('csc-dash-token-input');
            const linkInput = document.getElementById('csc-dash-link-input');
            const statusEl = document.getElementById('csc-dash-status');

            // Генерация — на сервере (regen=1): новый постоянный токен создаётся
            // и хэшируется в хранилище; в ответ приходит только одноразовая ссылка входа.
            statusEl.textContent = '💾 Сброс токена...';
            statusEl.style.color = '#d97706';

            const fd = new FormData();
            fd.append('action', 'csc_save_master_token');
            fd.append('mid', cscCurrentMasterId);
            fd.append('regen', '1');
            fd.append('_wpnonce', '<?php echo wp_create_nonce("csc_masters_action"); ?>');

            fetch('<?php echo esc_url(admin_url("admin-ajax.php")); ?>', {
                method: 'POST',
                body: fd
            })
            .then(r => {
                if (!r.ok) throw new Error('HTTP error: ' + r.status);
                return r.json();
            })
            .then(res => {
                if (res.success) {
                    linkInput.value = res.data.link || '';
                    tokenInput.value = '—';
                    statusEl.textContent = '✅ Новый токен сохранен на сервере!';
                    statusEl.style.color = '#10b981';
                } else {
                    statusEl.textContent = '⚠️ ' + (res.data?.message || 'Ошибка сброса токена.');
                    statusEl.style.color = '#d97706';
                }
            })
            .catch(() => {
                statusEl.textContent = '⚠️ Ошибка сети.';
                statusEl.style.color = '#d97706';
            });
        });

        document.getElementById('csc-dash-link-modal').addEventListener('click', function(e) {
            if (e.target === this) this.style.display = 'none';
        });

        document.addEventListener('keydown', function(e) {
            if (e.key === 'Escape') {
                const modal = document.getElementById('csc-dash-link-modal');
                if (modal) modal.style.display = 'none';
            }
        });
        </script>

        <!-- ============================================================
        МОДАЛЬНОЕ ОКНО: ПРОСМОТР ПОЛНОЙ КАРТОЧКИ МАСТЕРА (с картой Leaflet)
        ============================================================ -->
        <div id="csc-master-view-modal" style="display:none; position:fixed; top:0; left:0; width:100%; height:100%; background:rgba(15,23,42,0.7); z-index:99998; align-items:center; justify-content:center; padding:20px; overflow-y:auto;">
            <div style="background:#fff; border-radius:12px; width:100%; max-width:900px; max-height:90vh; overflow-y:auto; box-shadow:0 25px 50px rgba(0,0,0,0.25);">
                <!-- Шапка -->
                <div id="csc-mv-header" style="padding:20px 24px; border-bottom:1px solid #e2e8f0; display:flex; justify-content:space-between; align-items:center; position:sticky; top:0; background:#fff; z-index:10;">
                    <h2 id="csc-mv-title" style="margin:0; font-size:20px;">Загрузка...</h2>
                    <button type="button" id="csc-mv-close" style="background:none; border:none; font-size:24px; cursor:pointer; color:#64748b; padding:4px 8px; line-height:1;">✕</button>
                </div>

                <!-- Тело -->
                <div id="csc-mv-body" style="padding:24px;">
                    <!-- Состояние загрузки -->
                    <div id="csc-mv-loading" style="text-align:center; padding:60px 20px;">
                        <div style="display:inline-block; width:40px; height:40px; border:3px solid #e2e8f0; border-top-color:#2563eb; border-radius:50%; animation:csc-spin 1s linear infinite;"></div>
                        <p style="margin-top:16px; color:#64748b;">Загрузка данных мастера...</p>
                    </div>

                    <!-- Состояние ошибки -->
                    <div id="csc-mv-error" style="display:none; text-align:center; padding:40px 20px; color:#dc3232;">
                        <div style="font-size:48px; margin-bottom:16px;">⚠️</div>
                        <p id="csc-mv-error-text" style="margin:0; font-size:15px;"></p>
                    </div>

                    <!-- Основной контент -->
                    <div id="csc-mv-content" style="display:none;">
                        <!-- Профиль -->
                        <div style="display:grid; grid-template-columns:140px 1fr; gap:24px; margin-bottom:24px; padding-bottom:24px; border-bottom:1px solid #e2e8f0;">
                            <div id="csc-mv-avatar" style="width:140px; height:140px; border-radius:12px; background:#f1f5f9; display:flex; align-items:center; justify-content:center; font-size:48px; color:#64748b; overflow:hidden;">
                                <span>👤</span>
                            </div>
                            <div>
                                <h3 id="csc-mv-name" style="margin:0 0 8px 0; font-size:22px;"></h3>
                                <div id="csc-mv-status-badge" style="margin-bottom:12px;"></div>
                                <div style="display:grid; grid-template-columns:repeat(2, 1fr); gap:8px 16px; font-size:14px;">
                                    <div><span style="color:#64748b;">Город:</span> <span id="csc-mv-city"></span></div>
                                    <div><span style="color:#64748b;">Телефон:</span> <span id="csc-mv-phone"></span></div>
                                    <div><span style="color:#64748b;">Email:</span> <span id="csc-mv-email"></span></div>
                                    <div><span style="color:#64748b;">Telegram:</span> <span id="csc-mv-tg"></span></div>
                                    <div><span style="color:#64748b;">ID:</span> <code id="csc-mv-id"></code></div>
                                    <div><span style="color:#64748b;">Регистрация:</span> <span id="csc-mv-created"></span></div>
                                    <div><span style="color:#64748b;">Последний вход:</span> <span id="csc-mv-last-login"></span></div>
                                </div>
                            </div>
                        </div>

                        <!-- Вкладки -->
                        <div style="margin-bottom:16px; border-bottom:2px solid #e2e8f0; display:flex; gap:4px; overflow-x:auto;">
                            <button type="button" class="csc-mv-tab csc-mv-tab-active" data-tab="info">📋 Анкета</button>
                            <button type="button" class="csc-mv-tab" data-tab="work">🕒 Работа</button>
                            <button type="button" class="csc-mv-tab" data-tab="docs">📄 Документы</button>
                            <button type="button" class="csc-mv-tab" data-tab="stats">📊 Статистика</button>
                            <button type="button" class="csc-mv-tab" data-tab="finance">💰 Финансы</button>
                            <button type="button" class="csc-mv-tab" data-tab="reviews">⭐ Отзывы</button>
                            <button type="button" class="csc-mv-tab" data-tab="history">📜 История</button>
                        </div>

                        <!-- Содержимое вкладок -->
                        <div class="csc-mv-tab-content csc-mv-tab-active" data-tab="info">
                            <h4 style="margin-top:0;">О себе</h4>
                            <div id="csc-mv-about" style="padding:12px; background:#f8fafc; border-radius:6px; margin-bottom:16px; white-space:pre-wrap;"></div>

                            <h4>Специализации</h4>
                            <div id="csc-mv-specializations" style="margin-bottom:16px;"></div>

                            <h4>Опыт работы</h4>
                            <div id="csc-mv-experience" style="padding:12px; background:#f8fafc; border-radius:6px; margin-bottom:16px;"></div>

                            <h4>Зона обслуживания</h4>
                            <div id="csc-mv-zones" style="padding:12px; background:#f8fafc; border-radius:6px; margin-bottom:8px;"></div>
                            <div id="csc-mv-zone-map" style="width:100%; height:250px; border-radius:8px; border:1px solid #e2e8f0; background:#e2e8f0; display:none;"></div>
                        </div>

                        <div class="csc-mv-tab-content" data-tab="work" style="display:none;">
                            <h4 style="margin-top:0;">График работы</h4>
                            <div id="csc-mv-schedule" style="margin-bottom:16px;"></div>

                            <h4>Онлайн-статус</h4>
                            <div id="csc-mv-online" style="padding:12px; background:#f8fafc; border-radius:6px;"></div>
                        </div>

                        <div class="csc-mv-tab-content" data-tab="docs" style="display:none;">
                            <h4 style="margin-top:0;">Загруженные документы</h4>
                            <div id="csc-mv-documents" style="display:grid; grid-template-columns:repeat(auto-fill, minmax(250px, 1fr)); gap:12px;"></div>
                        </div>

                        <div class="csc-mv-tab-content" data-tab="stats" style="display:none;">
                            <div style="display:grid; grid-template-columns:repeat(4, 1fr); gap:12px; margin-bottom:24px;">
                                <div style="padding:16px; background:#eff6ff; border-radius:8px; text-align:center;">
                                    <div id="csc-mv-stat-total" style="font-size:28px; font-weight:700; color:#1e40af;">0</div>
                                    <div style="font-size:12px; color:#64748b; margin-top:4px;">Всего заказов</div>
                                </div>
                                <div style="padding:16px; background:#d1fae5; border-radius:8px; text-align:center;">
                                    <div id="csc-mv-stat-completed" style="font-size:28px; font-weight:700; color:#065f46;">0</div>
                                    <div style="font-size:12px; color:#64748b; margin-top:4px;">Выполнено</div>
                                </div>
                                <div style="padding:16px; background:#fee2e2; border-radius:8px; text-align:center;">
                                    <div id="csc-mv-stat-cancelled" style="font-size:28px; font-weight:700; color:#991b1b;">0</div>
                                    <div style="font-size:12px; color:#64748b; margin-top:4px;">Отменено</div>
                                </div>
                                <div style="padding:16px; background:#fef3c7; border-radius:8px; text-align:center;">
                                    <div id="csc-mv-stat-rating" style="font-size:28px; font-weight:700; color:#92400e;">0.0</div>
                                    <div style="font-size:12px; color:#64748b; margin-top:4px;">Рейтинг</div>
                                </div>
                            </div>

                            <h4>Последние заказы</h4>
                            <div id="csc-mv-recent-orders" style="margin-top:12px;"></div>
                        </div>

                        <div class="csc-mv-tab-content" data-tab="finance" style="display:none;">
                            <div style="display:grid; grid-template-columns:repeat(3, 1fr); gap:12px; margin-bottom:24px;">
                                <div style="padding:16px; background:#f0fdf4; border-radius:8px;">
                                    <div style="font-size:12px; color:#64748b; margin-bottom:4px;">Тип комиссии</div>
                                    <div id="csc-mv-comm-type" style="font-size:18px; font-weight:600; color:#166534;"></div>
                                </div>
                                <div style="padding:16px; background:#f0fdf4; border-radius:8px;">
                                    <div style="font-size:12px; color:#64748b; margin-bottom:4px;">Заработано всего</div>
                                    <div id="csc-mv-earned" style="font-size:18px; font-weight:600; color:#166534;"></div>
                                </div>
                                <div style="padding:16px; background:#fef2f2; border-radius:8px;">
                                    <div style="font-size:12px; color:#64748b; margin-bottom:4px;">Штрафы</div>
                                    <div id="csc-mv-penalties" style="font-size:18px; font-weight:600; color:#991b1b;"></div>
                                </div>
                            </div>

                            <h4>История выплат</h4>
                            <div id="csc-mv-payouts" style="margin-top:12px;"></div>
                        </div>

                        <div class="csc-mv-tab-content" data-tab="reviews" style="display:none;">
                            <div id="csc-mv-reviews" style="display:flex; flex-direction:column; gap:12px;"></div>
                        </div>

                        <div class="csc-mv-tab-content" data-tab="history" style="display:none;">
                            <div id="csc-mv-history-list" style="display:flex; flex-direction:column; gap:8px;"></div>
                        </div>
                    </div>
                </div>

                <!-- Футер с действиями -->
                <div id="csc-mv-footer" style="padding:16px 24px; border-top:1px solid #e2e8f0; background:#f8fafc; display:flex; justify-content:space-between; gap:8px; position:sticky; bottom:0;">
                    <div style="display:flex; gap:8px;">
                        <button type="button" class="button" id="csc-mv-approve-btn" style="background:#10b981; color:white; border-color:#10b981;">✅ Одобрить</button>
                        <button type="button" class="button" id="csc-mv-reject-btn" style="color:#dc3232;">❌ Отклонить</button>
                        <button type="button" class="button" id="csc-mv-block-btn" style="color:#dc3232;">🚫 Заблокировать</button>
                    </div>
                    <button type="button" class="button" id="csc-mv-close-bottom">Закрыть</button>
                </div>
            </div>
        </div>

        <style>
        @keyframes csc-spin {
            to { transform: rotate(360deg); }
        }
        .csc-mv-tab {
            background:none;
            border:none;
            padding:10px 16px;
            cursor:pointer;
            font-size:13px;
            font-weight:500;
            color:#64748b;
            border-bottom:2px solid transparent;
            margin-bottom:-2px;
            white-space:nowrap;
        }
        .csc-mv-tab:hover {
            color:#2563eb;
        }
        .csc-mv-tab.csc-mv-tab-active {
            color:#2563eb;
            border-bottom-color:#2563eb;
        }
        .csc-mv-history-item {
            padding:10px 12px;
            background:#f8fafc;
            border-left:3px solid #cbd5e1;
            border-radius:4px;
            font-size:13px;
        }
        .csc-mv-history-item.approve { border-left-color:#10b981; }
        .csc-mv-history-item.reject, .csc-mv-history-item.block { border-left-color:#dc3232; }
        .csc-mv-history-item.login { border-left-color:#3b82f6; }
        .csc-mv-history-date {
            display:block;
            font-size:11px;
            color:#94a3b8;
            margin-top:2px;
        }
        #csc-mv-zone-map {
            width: 100%;
            height: 250px;
            border-radius: 8px;
            border: 1px solid #e2e8f0;
            background: #e2e8f0;
            z-index: 1;
            overflow: hidden;
        }
        .csc-mv-tab-content .leaflet-container {
            z-index: 1;
        }
        .csc-mv-tab-content .leaflet-top,
        .csc-mv-tab-content .leaflet-bottom {
            z-index: 500;
        }
        </style>

        <script>
        // ============================================================
        // ПРОСМОТР МАСТЕРА: ПОЛНАЯ РЕАЛИЗАЦИЯ (с картой Leaflet)
        // ============================================================
        (function() {
            const modal = document.getElementById('csc-master-view-modal');
            if (!modal) return;

            const ajaxUrl = '<?php echo esc_url(admin_url("admin-ajax.php")); ?>';
            const nonce = '<?php echo wp_create_nonce("csc_masters_action"); ?>';

            let currentMasterId = '';
            let currentMasterData = null;
            let zoneMapInstance = null;

            // Делегирование для кнопки "Просмотр" и табов
            document.body.addEventListener('click', function(e) {
                const viewBtn = e.target.closest('.csc-view-master-btn');
                if (viewBtn) {
                    cscOpenMasterView(viewBtn.dataset.id);
                    return;
                }

                // Табы
                const tab = e.target.closest('.csc-mv-tab');
                if (tab) {
                    const tabName = tab.dataset.tab;
                    document.querySelectorAll('.csc-mv-tab').forEach(t => t.classList.remove('csc-mv-tab-active'));
                    document.querySelectorAll('.csc-mv-tab-content').forEach(c => c.style.display = 'none');
                    tab.classList.add('csc-mv-tab-active');
                    document.querySelector('.csc-mv-tab-content[data-tab="' + tabName + '"]').style.display = 'block';

                    // ИСПРАВЛЕНО 6.1: Инициализация карты при переключении на вкладку "Анкета"
                    if (tabName === 'info' && zoneMapInstance) {
                        setTimeout(() => {
                            if (zoneMapInstance) zoneMapInstance.invalidateSize();
                        }, 100);
                    }
                    return;
                }
            });

            // Закрытие
            document.getElementById('csc-mv-close').addEventListener('click', cscCloseMasterView);
            document.getElementById('csc-mv-close-bottom').addEventListener('click', cscCloseMasterView);
            modal.addEventListener('click', function(e) {
                if (e.target === modal) cscCloseMasterView();
            });
            document.addEventListener('keydown', function(e) {
                if (e.key === 'Escape' && modal.style.display === 'flex') cscCloseMasterView();
            });

            // Кнопки действий из модального окна
            document.getElementById('csc-mv-approve-btn').addEventListener('click', function() {
                if (!currentMasterId) return;
                if (confirm('Одобрить этого мастера?')) {
                    cscModerateMasterFromModal(currentMasterId, 'approve', '');
                }
            });

            document.getElementById('csc-mv-reject-btn').addEventListener('click', function() {
                if (!currentMasterId) return;
                const reason = prompt('Укажите причину отклонения:');
                if (reason === null) return;
                if (reason.trim() === '' && !confirm('Причина не указана. Продолжить?')) return;
                cscModerateMasterFromModal(currentMasterId, 'reject', reason);
            });

            document.getElementById('csc-mv-block-btn').addEventListener('click', function() {
                if (!currentMasterId) return;
                const reason = prompt('Укажите причину блокировки:');
                if (reason === null) return;
                if (reason.trim() === '' && !confirm('Причина не указана. Продолжить?')) return;
                cscModerateMasterFromModal(currentMasterId, 'block', reason);
            });

            if (!window.cscOpenMasterView) {
            function cscOpenMasterView(masterId) {
                currentMasterId = masterId;
                currentMasterData = null;
                zoneMapInstance = null;

                document.getElementById('csc-mv-loading').style.display = 'block';
                document.getElementById('csc-mv-error').style.display = 'none';
                document.getElementById('csc-mv-content').style.display = 'none';
                document.getElementById('csc-mv-title').textContent = 'Загрузка...';

                document.querySelectorAll('.csc-mv-tab').forEach((t, i) => {
                    t.classList.toggle('csc-mv-tab-active', i === 0);
                });
                document.querySelectorAll('.csc-mv-tab-content').forEach((c, i) => {
                    c.style.display = i === 0 ? 'block' : 'none';
                });

                modal.style.display = 'flex';

                const fd = new FormData();
                fd.append('action', 'csc_get_master_details');
                fd.append('mid', masterId);
                fd.append('_wpnonce', nonce);

                fetch(ajaxUrl, { method: 'POST', body: fd })
                    .then(r => {
                        if (!r.ok) throw new Error('HTTP error: ' + r.status);
                        return r.json();
                    })
                    .then(res => {
                        if (res.success) {
                            currentMasterData = res.data.master;
                            cscRenderMasterView(currentMasterData);
                        } else {
                            cscShowViewError(res.data?.message || 'Не удалось загрузить данные');
                        }
                    })
                    .catch(err => {
                        cscShowViewError('Ошибка сети: ' + err.message);
                    });
            }

            if (!window.cscShowViewError) {
            function cscShowViewError(message) {
                document.getElementById('csc-mv-loading').style.display = 'none';
                document.getElementById('csc-mv-error').style.display = 'block';
                document.getElementById('csc-mv-error-text').textContent = message;
            }

            if (!window.cscRenderMasterView) {
            function cscRenderMasterView(data) {
                document.getElementById('csc-mv-loading').style.display = 'none';
                document.getElementById('csc-mv-content').style.display = 'block';

                // Шапка
                document.getElementById('csc-mv-title').textContent = '👤 ' + data.name;
                document.getElementById('csc-mv-name').textContent = data.name;
                document.getElementById('csc-mv-id').textContent = data.id;

                // Статус бейдж
                const statusMap = {
                    'pending': { label: '⏳ На модерации', color: '#fef3c7', text: '#92400e' },
                    'active': { label: '🟢 Одобрен', color: '#d1fae5', text: '#065f46' },
                    'blocked': { label: '🚫 Заблокирован', color: '#fee2e2', text: '#991b1b' },
                    'rejected': { label: '❌ Отклонён', color: '#fee2e2', text: '#991b1b' }
                };
                const st = statusMap[data.status] || statusMap['active'];
                document.getElementById('csc-mv-status-badge').innerHTML =
                    '<span style="display:inline-block; padding:4px 12px; border-radius:12px; font-size:12px; font-weight:600; background:' + st.color + '; color:' + st.text + ';">' + st.label + '</span>';

                // Показываем/скрываем кнопки в зависимости от статуса
                const approveBtn = document.getElementById('csc-mv-approve-btn');
                const rejectBtn = document.getElementById('csc-mv-reject-btn');
                const blockBtn = document.getElementById('csc-mv-block-btn');

                approveBtn.style.display = (data.status === 'pending' || data.status === 'blocked' || data.status === 'rejected') ? 'inline-block' : 'none';
                rejectBtn.style.display = data.status === 'pending' ? 'inline-block' : 'none';
                blockBtn.style.display = data.status === 'active' ? 'inline-block' : 'none';
                blockBtn.textContent = data.status === 'active' ? '🚫 Заблокировать' : '🔓 Разблокировать';

                // Аватар
                const avatarEl = document.getElementById('csc-mv-avatar');
                if (data.avatar) {
                    avatarEl.innerHTML = '<img src="' + data.avatar + '" style="width:100%; height:100%; object-fit:cover;">';
                } else {
                    const initials = data.name.split(' ').map(n => n[0]).join('').substring(0, 2).toUpperCase();
                    avatarEl.innerHTML = '<span style="font-size:42px; font-weight:600; color:#64748b;">' + initials + '</span>';
                }

                // Контакты (восстановлено поле "Город" из 3.6)
                document.getElementById('csc-mv-city').textContent = data.city_name || data.city || '—';
                document.getElementById('csc-mv-phone').textContent = data.phone || '—';
                document.getElementById('csc-mv-email').textContent = data.email || '—';
                document.getElementById('csc-mv-tg').textContent = data.tg_chat_id || '—';
                document.getElementById('csc-mv-created').textContent = data.created_at ? cscFormatDate(data.created_at) : '—';
                document.getElementById('csc-mv-last-login').textContent = data.last_login ? cscFormatDate(data.last_login) : 'Никогда';

                // Вкладка "Анкета"
                document.getElementById('csc-mv-about').textContent = data.about || 'Не указано';
                document.getElementById('csc-mv-experience').textContent = data.experience || 'Не указано';

                const specsHtml = Array.isArray(data.specializations) && data.specializations.length > 0
                    ? data.specializations.map(s => '<span style="display:inline-block; padding:4px 10px; background:#eff6ff; color:#1e40af; border-radius:6px; font-size:12px; margin:0 4px 4px 0;">' + cscEscapeHtml(s) + '</span>').join('')
                    : '<span style="color:#94a3b8;">Не указаны</span>';
                document.getElementById('csc-mv-specializations').innerHTML = specsHtml;

                const zonesHtml = Array.isArray(data.zones) && data.zones.length > 0
                    ? data.zones.map(z => '<div style="padding:8px 12px; background:#f1f5f9; border-radius:4px; margin-bottom:4px;">📍 ' + cscEscapeHtml(z) + '</div>').join('')
                    : '<span style="color:#94a3b8;">Зоны не указаны</span>';
                document.getElementById('csc-mv-zones').innerHTML = zonesHtml;

                // === КАРТА ЗОНЫ ОБСЛУЖИВАНИЯ (Leaflet + OpenStreetMap) ===
                const zoneMapEl = document.getElementById('csc-mv-zone-map');
                if (zoneMapEl && data.service_lat && data.service_lng) {
                    // Проверка загрузки Leaflet
                    if (typeof L === 'undefined') {
                        console.error('[CSC] Leaflet не загружен');
                        zoneMapEl.style.display = 'none';
                    } else {
                        zoneMapEl.style.display = 'block';

                        if (zoneMapEl._leaflet_id) {
                            zoneMapEl.innerHTML = '';
                            delete zoneMapEl._leaflet_id;
                        }

                        zoneMapInstance = L.map('csc-mv-zone-map', {
                            center: [data.service_lat, data.service_lng],
                            zoom: 12,
                            scrollWheelZoom: false,
                        });

                        L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
                            attribution: '&copy; OpenStreetMap',
                            maxZoom: 18,
                        }).addTo(zoneMapInstance);

                        L.marker([data.service_lat, data.service_lng], {
                            title: 'Точка выезда',
                        }).addTo(zoneMapInstance);

                        L.circle([data.service_lat, data.service_lng], {
                            radius: (data.service_radius || 10) * 1000,
                            color: '#6366f1',
                            weight: 2,
                            fillColor: '#6366f1',
                            fillOpacity: 0.12,
                        }).addTo(zoneMapInstance);

                        if (data.service_address) {
                            document.getElementById('csc-mv-zones').innerHTML =
                                '<div style="padding:8px 12px; background:#f1f5f9; border-radius:4px;">📍 ' + cscEscapeHtml(data.service_address) + '</div>' +
                                '<div style="padding:4px 12px; color:#64748b; font-size:12px;">Радиус: ' + (data.service_radius || 10) + ' км</div>';
                        }

                        // Отложенная инициализация размера карты
                        setTimeout(() => {
                            if (zoneMapInstance) zoneMapInstance.invalidateSize();
                        }, 300);
                    }
                } else if (zoneMapEl) {
                    zoneMapEl.style.display = 'none';
                }

                // Вкладка "Работа"
                const daysNames = ['Вс', 'Пн', 'Вт', 'Ср', 'Чт', 'Пт', 'Сб'];
                const activeDays = Array.isArray(data.work_days) ? data.work_days : [];
                let scheduleHtml = '<div style="display:flex; gap:4px; flex-wrap:wrap; margin-bottom:16px;">';
                for (let i = 1; i <= 7; i++) {
                    const active = activeDays.includes(i);
                    scheduleHtml += '<span style="padding:6px 10px; background:' + (active ? '#dbeafe' : '#f1f5f9') + '; color:' + (active ? '#1e40af' : '#94a3b8') + '; border-radius:6px; font-size:12px;">' + daysNames[i] + '</span>';
                }
                scheduleHtml += '</div>';
                scheduleHtml += '<div style="padding:12px; background:#f8fafc; border-radius:6px;">';
                scheduleHtml += '<strong>Часы работы:</strong> ' + String(data.work_hours_start).padStart(2, '0') + ':00 — ' + String(data.work_hours_end).padStart(2, '0') + ':00';
                scheduleHtml += '</div>';

                // ВОССТАНОВЛЕНО 6.2: отображение детального расписания schedule
                if (data.schedule && typeof data.schedule === 'object' && Object.keys(data.schedule).length > 0) {
                    scheduleHtml += '<h4 style="margin-top:16px;">Детальное расписание по дням</h4>';
                    scheduleHtml += '<div style="padding:12px; background:#f8fafc; border-radius:6px;">';
                    const dayNamesRu = {
                        'monday': 'Понедельник',
                        'tuesday': 'Вторник',
                        'wednesday': 'Среда',
                        'thursday': 'Четверг',
                        'friday': 'Пятница',
                        'saturday': 'Суббота',
                        'sunday': 'Воскресенье'
                    };
                    for (const [dayKey, dayData] of Object.entries(data.schedule)) {
                        if (!dayData || !dayData.enabled) continue;
                        scheduleHtml += '<div style="padding:4px 0; font-size:13px;">';
                        scheduleHtml += '<strong>' + (dayNamesRu[dayKey] || dayKey) + ':</strong> ' + cscEscapeHtml(dayData.from || '09:00') + ' — ' + cscEscapeHtml(dayData.to || '18:00');
                        scheduleHtml += '</div>';
                    }
                    scheduleHtml += '</div>';
                }

                document.getElementById('csc-mv-schedule').innerHTML = scheduleHtml;

                document.getElementById('csc-mv-online').innerHTML =
                    '<span style="display:inline-flex; align-items:center; gap:6px;">' +
                    '<span style="width:10px; height:10px; border-radius:50%; background:' + (data.is_online ? '#10b981' : '#94a3b8') + ';"></span>' +
                    '<span>' + (data.is_online ? 'Онлайн — принимает заказы' : 'Офлайн — заказы не принимает') + '</span></span>';

                // Вкладка "Документы"
                let docsHtml = '';
                if (Array.isArray(data.documents) && data.documents.length > 0) {
                    data.documents.forEach(doc => {
                        docsHtml += '<a href="' + cscEscapeHtml(doc.url) + '" target="_blank" style="padding:12px; background:#f8fafc; border-radius:8px; text-decoration:none; color:inherit; display:block; border:1px solid #e2e8f0;">';
                        docsHtml += '<div style="font-size:24px; margin-bottom:4px;">📄</div>';
                        docsHtml += '<div style="font-size:13px; font-weight:500;">' + cscEscapeHtml(doc.name || 'Документ') + '</div>';
                        docsHtml += '<div style="font-size:11px; color:#64748b;">Открыть ↗</div>';
                        docsHtml += '</a>';
                    });
                } else {
                    docsHtml = '<div style="grid-column:1/-1; text-align:center; padding:40px; color:#94a3b8;">Документы не загружены</div>';
                }
                document.getElementById('csc-mv-documents').innerHTML = docsHtml;

                // Вкладка "Статистика"
                document.getElementById('csc-mv-stat-total').textContent = data.stats.total;
                document.getElementById('csc-mv-stat-completed').textContent = data.stats.completed;
                document.getElementById('csc-mv-stat-cancelled').textContent = data.stats.cancelled;
                document.getElementById('csc-mv-stat-rating').textContent = (data.stats.rating || 0).toFixed(1) + ' ⭐';

                let ordersHtml = '';
                if (data.stats.recent_orders.length > 0) {
                    ordersHtml = '<table style="width:100%; border-collapse:collapse; font-size:13px;">';
                    ordersHtml += '<thead><tr style="background:#f8fafc;"><th style="padding:8px; text-align:left; border-bottom:1px solid #e2e8f0;">Дата</th><th style="padding:8px; text-align:left; border-bottom:1px solid #e2e8f0;">Клиент</th><th style="padding:8px; text-align:left; border-bottom:1px solid #e2e8f0;">Услуга</th><th style="padding:8px; text-align:left; border-bottom:1px solid #e2e8f0;">Статус</th></tr></thead><tbody>';
                    data.stats.recent_orders.forEach(o => {
                        ordersHtml += '<tr>';
                        ordersHtml += '<td style="padding:8px; border-bottom:1px solid #f1f5f9;">' + cscEscapeHtml((o.date || '') + ' ' + (o.time || '')) + '</td>';
                        ordersHtml += '<td style="padding:8px; border-bottom:1px solid #f1f5f9;">' + cscEscapeHtml(o.client_name || o.phone || '—') + '</td>';
                        ordersHtml += '<td style="padding:8px; border-bottom:1px solid #f1f5f9;">' + cscEscapeHtml(o.service || '—') + '</td>';
                        ordersHtml += '<td style="padding:8px; border-bottom:1px solid #f1f5f9;">' + cscEscapeHtml(o.status || '—') + '</td>';
                        ordersHtml += '</tr>';
                    });
                    ordersHtml += '</tbody></table>';
                } else {
                    ordersHtml = '<div style="text-align:center; padding:20px; color:#94a3b8;">Заказов пока нет</div>';
                }
                document.getElementById('csc-mv-recent-orders').innerHTML = ordersHtml;

                // Вкладка "Финансы"
                const commTypeLabel = data.comm_type === 'percent' ? (data.comm_val + '%') : (data.comm_val + ' ₽');
                document.getElementById('csc-mv-comm-type').textContent = commTypeLabel;
                document.getElementById('csc-mv-earned').textContent = cscFormatMoney(data.earned_total);
                document.getElementById('csc-mv-penalties').textContent = cscFormatMoney(data.penalties_amount) + ' (' + data.penalties_total + ' шт.)';

                let payoutsHtml = '';
                if (Array.isArray(data.payouts) && data.payouts.length > 0) {
                    payoutsHtml = '<table style="width:100%; border-collapse:collapse; font-size:13px;">';
                    payoutsHtml += '<thead><tr style="background:#f8fafc;"><th style="padding:8px; text-align:left;">Дата</th><th style="padding:8px; text-align:right;">Сумма</th><th style="padding:8px; text-align:left;">Статус</th></tr></thead><tbody>';
                    data.payouts.forEach(p => {
                        payoutsHtml += '<tr>';
                        payoutsHtml += '<td style="padding:8px; border-bottom:1px solid #f1f5f9;">' + cscEscapeHtml(p.date || '') + '</td>';
                        payoutsHtml += '<td style="padding:8px; text-align:right; border-bottom:1px solid #f1f5f9;">' + cscFormatMoney(p.amount) + '</td>';
                        payoutsHtml += '<td style="padding:8px; border-bottom:1px solid #f1f5f9;">' + cscEscapeHtml(p.status || '') + '</td>';
                        payoutsHtml += '</tr>';
                    });
                    payoutsHtml += '</tbody></table>';
                } else {
                    payoutsHtml = '<div style="text-align:center; padding:20px; color:#94a3b8;">Выплат пока нет</div>';
                }
                document.getElementById('csc-mv-payouts').innerHTML = payoutsHtml;

                // Вкладка "Отзывы"
                let reviewsHtml = '';
                if (Array.isArray(data.reviews) && data.reviews.length > 0) {
                    // Сохраняем во временную переменную для безопасного вызова Lightbox
                    window.CSC_ADMIN_MASTER_REVIEWS = data.reviews;
                    data.reviews.forEach((r, rIdx) => {
                        const stars = '⭐'.repeat(Math.round(r.rating || 0));
                        let photosHtml = '';
                        if (Array.isArray(r.photos) && r.photos.length > 0) {
                            photosHtml = '<div style="margin-top:10px; display:flex; flex-wrap:wrap; gap:6px;">';
                            r.photos.forEach((p, pIdx) => {
                                const url = p.url || p;
                                photosHtml += `<img src="${cscEscapeHtml(url)}" class="csc-review-photo-thumb" onclick="window.CSC_Lightbox.open(window.CSC_ADMIN_MASTER_REVIEWS[${rIdx}].photos.map(x => x.url || x), ${pIdx})" alt="Фото отзыва">`;
                            });
                            photosHtml += '</div>';
                        }
                        reviewsHtml += `<div style="padding:14px; background:#f8fafc; border-radius:8px; margin-bottom:12px; border:1px solid #e2e8f0;">
                            <div style="display:flex; justify-content:space-between; margin-bottom:8px; align-items:center;">
                                <strong style="font-size:14px;">${cscEscapeHtml(r.client_name || 'Клиент')} ${r.client_phone ? '<span style="color:#64748b; font-weight:normal; font-size:12px;">(' + cscEscapeHtml(r.client_phone) + ')</span>' : ''}</strong>
                                <span style="font-size:16px; letter-spacing:2px;">${stars}</span>
                            </div>
                            <div style="font-size:13px; color:#475569; white-space:pre-wrap; margin-bottom:8px; line-height:1.5;">${cscEscapeHtml(r.comment || '')}</div>
                            ${photosHtml}
                            <div style="font-size:11px; color:#94a3b8; margin-top:10px; display:flex; justify-content:space-between; border-top:1px solid #e2e8f0; padding-top:8px;">
                                <span>Заказ: <a href="#" onclick="if(typeof openOrderLogModal==='function') openOrderLogModal('${cscEscapeHtml(r.order_number)}'); return false;" style="color:#3b82f6; text-decoration:none; font-weight:600;">#${cscEscapeHtml(r.order_number)}</a></span>
                                <span>${cscFormatDate(r.date || '')}</span>
                            </div>
                        </div>`;
                    });
                } else {
                    reviewsHtml = '<div style="text-align:center; padding:40px; color:#94a3b8;">Отзывов пока нет</div>';
                }
                document.getElementById('csc-mv-reviews').innerHTML = reviewsHtml;

                // Вкладка "История"
                let historyHtml = '';
                const sortedHistory = [...data.history].sort((a, b) => new Date(b.date) - new Date(a.date));
                if (sortedHistory.length > 0) {
                    sortedHistory.forEach(h => {
                        historyHtml += '<div class="csc-mv-history-item ' + cscEscapeHtml(h.action || '') + '">';
                        historyHtml += '<div><strong>' + cscEscapeHtml(h.note || h.action || 'Действие') + '</strong></div>';
                        historyHtml += '<span class="csc-mv-history-date">' + cscFormatDate(h.date || '') + ' · ' + cscEscapeHtml(h.by || '') + '</span>';
                        historyHtml += '</div>';
                    });
                } else {
                    historyHtml = '<div style="text-align:center; padding:40px; color:#94a3b8;">История пуста</div>';
                }
                document.getElementById('csc-mv-history-list').innerHTML = historyHtml;
            }

            function cscModerateMasterFromModal(masterId, action, reason) {
                const fd = new FormData();
                fd.append('action', 'csc_moderate_master');
                fd.append('mid', masterId);
                fd.append('moderation_action', action);
                fd.append('reason', reason);
                fd.append('_wpnonce', nonce);

                fetch(ajaxUrl, { method: 'POST', body: fd })
                    .then(r => {
                        if (!r.ok) throw new Error('HTTP error: ' + r.status);
                        return r.json();
                    })
                    .then(res => {
                        if (res.success) {
                            cscCloseMasterView();
                            if (typeof location !== 'undefined') location.reload();
                        } else {
                            alert('Ошибка: ' + (res.data?.message || 'Неизвестная ошибка'));
                        }
                    })
                    .catch(err => {
                        alert('Ошибка сети: ' + err.message);
                    });
            }

            function cscCloseMasterView() {
                modal.style.display = 'none';
                currentMasterId = '';
                currentMasterData = null;
                zoneMapInstance = null;
            }

            // Утилиты
            function cscFormatDate(str) {
                if (!str) return '—';
                const d = new Date(str);
                if (isNaN(d.getTime())) return str;
                return d.toLocaleDateString('ru-RU') + ' ' + d.toLocaleTimeString('ru-RU', { hour: '2-digit', minute: '2-digit' });
            }

            function cscFormatMoney(val) {
                const num = parseFloat(val) || 0;
                return num.toLocaleString('ru-RU', { style: 'currency', currency: 'RUB', maximumFractionDigits: 0 });
            }

            if (!window.cscEscapeHtml) {
            function cscEscapeHtml(str) {
                if (str === null || str === undefined) return '';
                return String(str)
                    .replace(/&/g, '&amp;')
                    .replace(/</g, '&lt;')
                    .replace(/>/g, '&gt;')
                    .replace(/"/g, '&quot;')
                    .replace(/'/g, '&#039;');
            }
        })();
        </script>
        <?php
    }
}
/**
* Вспомогательная функция для рендера строки таблицы.
* Бейдж модерационного статуса + кнопки действий модерации + кнопка просмотра
* + вывод причины отклонения/блокировки.
*/
if (!function_exists('csc_render_master_table_row')) {
    function csc_render_master_table_row($id, $m, $masters_count = 0) {
        $comm_type = $m['comm_type'] ?? 'percent';
        $comm_val = isset($m['comm_val']) ? $m['comm_val'] : 30;
        $dash_token = $m['dashboard_token'] ?? '';
        $status = $m['status'] ?? 'active';
        $is_online = isset($m['is_online']) ? !empty($m['is_online']) : true;
        $penalties_total = isset($m['penalties_total']) ? intval($m['penalties_total']) : 0;

        // Цвета и тексты статусов
        $status_labels = array(
            'pending'  => array('label' => '⏳ На модерации', 'color' => '#fef3c7', 'text' => '#92400e'),
            'active'   => array('label' => '🟢 Одобрен', 'color' => '#d1fae5', 'text' => '#065f46'),
            'inactive' => array('label' => '⚪ Неактивен', 'color' => '#f3f4f6', 'text' => '#4b5563'),
            'blocked'  => array('label' => '🚫 Заблокирован', 'color' => '#fee2e2', 'text' => '#991b1b'),
            'rejected' => array('label' => '❌ Отклонён', 'color' => '#fee2e2', 'text' => '#991b1b'),
        );
        $s_info = isset($status_labels[$status]) ? $status_labels[$status] : $status_labels['active'];
        ?>
        <tr>
            <td>
                <input type="hidden" name="masters[<?php echo esc_attr($id); ?>][id]" value="<?php echo esc_attr($id); ?>">
                <input type="hidden" name="masters[<?php echo esc_attr($id); ?>][status]" value="<?php echo esc_attr($status); ?>">
                <input type="text" name="masters[<?php echo esc_attr($id); ?>][name]" value="<?php echo esc_attr($m['name']); ?>" style="width:100%;" required>
            </td>
            <td>
                <input type="text" name="masters[<?php echo esc_attr($id); ?>][phone]" value="<?php echo esc_attr($m['phone'] ?? ''); ?>" style="width:100%;">
            </td>
            <td>
                <input type="text" name="masters[<?php echo esc_attr($id); ?>][tg_chat_id]" value="<?php echo esc_attr($m['tg_chat_id'] ?? ''); ?>" placeholder="ID чата" style="width:100%;">
            </td>
            <td>
                <div style="display:flex; gap:5px; align-items:center;">
                    <input type="number" name="masters[<?php echo esc_attr($id); ?>][comm_val]" value="<?php echo esc_attr($comm_val); ?>" style="width:70px;" min="0" step="0.01">
                    <select name="masters[<?php echo esc_attr($id); ?>][comm_type]">
                        <option value="percent" <?php selected($comm_type, 'percent'); ?>>%</option>
                        <option value="fixed" <?php selected($comm_type, 'fixed'); ?>>₽</option>
                    </select>
                </div>
            </td>
            <td>
                <input type="color" name="masters[<?php echo esc_attr($id); ?>][color]" value="<?php echo esc_attr($m['color'] ?? '#3b82f6'); ?>" style="width:50px; height:30px; padding:0; border:none;">
            </td>
            <td>
                <label>
                    <input type="checkbox" name="masters[<?php echo esc_attr($id); ?>][active]" value="1" <?php checked(!empty($m['active'])); ?>>
                    Активен
                </label>
            </td>
            <td>
                <!-- Бейдж модерационного статуса -->
                <span class="csc-moderation-badge" style="display:inline-block; padding:3px 8px; border-radius:10px; font-size:11px; font-weight:600; background:<?php echo $s_info['color']; ?>; color:<?php echo $s_info['text']; ?>; margin-bottom:4px;">
                    <?php echo $s_info['label']; ?>
                </span>
                <?php if ($penalties_total > 0): ?>
                    <br><small style="color:#dc3232;">⚠️ Штрафы: <?php echo $penalties_total; ?></small>
                <?php endif; ?>
                <?php if ($status === 'rejected' && !empty($m['rejection_reason'])): ?>
                    <br><small style="color:#666;" title="<?php echo esc_attr($m['rejection_reason']); ?>">Причина: <?php echo esc_html(mb_strimwidth($m['rejection_reason'], 0, 30, '...')); ?></small>
                <?php endif; ?>
                <?php if ($status === 'blocked' && !empty($m['block_reason'])): ?>
                    <br><small style="color:#666;" title="<?php echo esc_attr($m['block_reason']); ?>">Блок: <?php echo esc_html(mb_strimwidth($m['block_reason'], 0, 30, '...')); ?></small>
                <?php endif; ?>
            </td>
            <td>
                <!-- Скрытое поле для сохранения dashboard_token -->
                <input type="hidden" name="masters[<?php echo esc_attr($id); ?>][dashboard_token]" value="<?php echo esc_attr($dash_token); ?>">

                <!-- Кнопка полной карточки мастера -->
                <button type="button" class="button button-small csc-view-master-btn" data-id="<?php echo esc_attr($id); ?>" data-name="<?php echo esc_attr($m['name']); ?>" title="Полная карточка мастера" style="margin-right:4px;">👁️ Просмотр</button>

                <!-- Использование data-атрибутов и классов для защиты от XSS -->
                <button type="button" class="button button-small csc-btn-dash" data-id="<?php echo esc_attr($id); ?>" data-name="<?php echo esc_attr($m['name']); ?>" title="Показать ссылку на кабинет мастера" style="margin-right:4px;">🔗 Кабинет</button>

                <!-- === Кнопки модерации === -->
                <?php if ($status === 'pending'): ?>
                    <button type="button" class="button button-small button-primary csc-btn-mod" data-id="<?php echo esc_attr($id); ?>" data-action="approve" data-name="<?php echo esc_attr($m['name']); ?>" style="background:#10b981; border-color:#10b981;">✅ Одобрить</button>
                    <button type="button" class="button button-small csc-btn-mod" data-id="<?php echo esc_attr($id); ?>" data-action="reject" data-name="<?php echo esc_attr($m['name']); ?>" style="color:#dc3232;">❌ Отклонить</button>
                <?php elseif ($status === 'active'): ?>
                    <button type="button" class="button button-small csc-btn-mod" data-id="<?php echo esc_attr($id); ?>" data-action="block" data-name="<?php echo esc_attr($m['name']); ?>" style="color:#dc3232;" title="Заблокировать мастера">🚫 Блок</button>
                <?php elseif ($status === 'blocked' || $status === 'rejected'): ?>
                    <button type="button" class="button button-small csc-btn-mod" data-id="<?php echo esc_attr($id); ?>" data-action="approve" data-name="<?php echo esc_attr($m['name']); ?>" style="background:#10b981; border-color:#10b981; color:white;">🔓 Разблок</button>
                <?php endif; ?>

                <?php if ($masters_count > 1): ?>
                    <button type="button" class="button button-link-delete csc-btn-delete">Удалить</button>
                <?php else: ?>
                    <span class="description">Основной</span>
                <?php endif; ?>
            </td>
        </tr>
        <?php
    }
}

// ============================================================
// 4. AJAX: СОХРАНЕНИЕ ТОКЕНА ДАШБОРДА (УЛУЧШЕНИЕ)
// ============================================================
add_action('wp_ajax_csc_save_master_token', 'csc_save_master_token_ajax');

if (!function_exists('csc_save_master_token_ajax')) {
    function csc_save_master_token_ajax() {
        // Проверка прав и nonce
        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => 'Доступ запрещен']);
        }
        if (!check_ajax_referer('csc_masters_action', '_wpnonce', false)) {
            wp_send_json_error(['message' => 'Ошибка безопасности']);
        }

        $mid = sanitize_text_field($_POST['mid'] ?? '');

        // ИСПРАВЛЕНО (SHA-256): серверная генерация токена вместо клиентской.
        // Административный UI больше не передаёт сгенерированные токены через браузер
        // (защита от утечки и подмены); значение из формы принимается только в формате
        // stored (хэш sha256$...), для обратной совместимости старых форм.
        $submitted = sanitize_text_field($_POST['token'] ?? '');
        $regen     = !empty($_POST['regen']);

        if (empty($mid)) {
            wp_send_json_error(['message' => 'Недостаточно данных']);
        }

        // Получаем свежий список мастеров (сброс кэша)
        $masters = csc_get_masters(true);

        if (!isset($masters[$mid])) {
            wp_send_json_error(['message' => 'Мастер не найден']);
        }

        $existing = (string) ($masters[$mid]['dashboard_token'] ?? '');

        if ($regen || $existing === '' || strlen($existing) < 16) {
            // Новый постоянный токен генерируется на сервере; в хранилище — только хэш.
            $raw = function_exists('csc_generate_token') ? csc_generate_token() : bin2hex(random_bytes(24));
            $stored = function_exists('csc_hash_token') ? csc_hash_token($raw) : $raw;
            $masters[$mid]['dashboard_token'] = $stored;
            csc_save_masters($masters);
        } elseif (csc_is_hashed_token($existing)) {
            // Уже хранится хэш — ничего не меняем (идемпотентно).
            $stored = $existing;
        } elseif (csc_is_hashed_token($submitted) && $submitted === $existing) {
            // Форма прислала stored-значение без изменений.
            $stored = $submitted;
        } elseif (strlen($existing) >= 16) {
            // Legacy открытый токен — миграция в хэш при сохранении.
            $stored = function_exists('csc_hash_token') ? csc_hash_token($existing) : $existing;
            $masters[$mid]['dashboard_token'] = $stored;
            csc_save_masters($masters);
        } else {
            wp_send_json_error(['message' => 'Некорректный токен']);
        }

        // Ссылка входа формируется с одноразовым входным креденшелом,
        // а не с stored-значением (хэш в URL недопустим).
        $login_token = function_exists('csc_issue_master_login_token')
            ? csc_issue_master_login_token($mid, $masters)
            : '';

        // «Живой» постоянный токен не возвращается и не показывается в UI:
        // ссылка входа строится на одноразовом входном креденшел.
        wp_send_json_success([
            'message' => 'Токен сохранен',
            'link'    => $login_token !== ''
                ? home_url('/?csc_master_dashboard=1&mid=' . urlencode($mid) . '&token=' . urlencode($login_token))
                : home_url('/?csc_master_dashboard=1'),
        ]);
    }
}

// ============================================================
// 5. AJAX: МОДЕРАЦИОННЫЕ ДЕЙСТВИЯ С МАСТЕРАМИ
//    ВОССТАНОВЛЕНО 6.2: отправка email мастеру + внутрикабинетные уведомления (из 3.6)
// ============================================================
add_action('wp_ajax_csc_moderate_master', 'csc_moderate_master_ajax');

if (!function_exists('csc_moderate_master_ajax')) {
    function csc_moderate_master_ajax() {
        if (!current_user_can('manage_options')) {
            wp_send_json_error(array('message' => 'Доступ запрещен'));
        }
        if (!check_ajax_referer('csc_masters_action', '_wpnonce', false)) {
            wp_send_json_error(array('message' => 'Ошибка безопасности'));
        }

        $mid = sanitize_text_field($_POST['mid'] ?? '');
        $action = sanitize_text_field($_POST['moderation_action'] ?? '');
        $reason = sanitize_text_field($_POST['reason'] ?? '');

        if (empty($mid) || empty($action)) {
            wp_send_json_error(array('message' => 'Недостаточно данных'));
        }

        $valid_actions = array('approve', 'reject', 'block', 'unblock');
        if (!in_array($action, $valid_actions)) {
            wp_send_json_error(array('message' => 'Недопустимое действие'));
        }

        $masters = csc_get_masters(true);
        if (!isset($masters[$mid])) {
            wp_send_json_error(array('message' => 'Мастер не найден'));
        }

        $master = &$masters[$mid];
        $old_status = $master['status'] ?? 'active';

        switch ($action) {
            case 'approve':
                $master['status'] = 'active';
                $master['is_online'] = true;
                $master['approved_at'] = current_time('mysql');
                $master['rejection_reason'] = '';
                $master['block_reason'] = '';
                break;
            case 'reject':
                $master['status'] = 'rejected';
                $master['is_online'] = false;
                $master['rejection_reason'] = !empty($reason) ? $reason : 'Причина не указана';
                break;
            case 'block':
                $master['status'] = 'blocked';
                $master['is_online'] = false;
                $master['block_reason'] = !empty($reason) ? $reason : 'Нарушение правил';
                break;
            case 'unblock':
                $master['status'] = 'active';
                $master['is_online'] = true;
                $master['penalties_total'] = 0;
                $master['penalties_amount'] = 0;
                $master['block_reason'] = '';
                break;
        }

        // Запись в историю модерации
        if (!isset($master['moderation_history']) || !is_array($master['moderation_history'])) {
            $master['moderation_history'] = array();
        }
        $master['moderation_history'][] = array(
            'action' => $action,
            'date'   => current_time('mysql'),
            'by'     => wp_get_current_user()->user_login,
            'note'   => !empty($reason) ? $reason : ($action === 'approve' ? 'Одобрение' : ($action === 'reject' ? 'Отклонение' : ($action === 'block' ? 'Блокировка' : 'Разблокировка'))),
        );

        csc_save_masters($masters);

        // ============================================================
        // ВОССТАНОВЛЕНО 6.2: ОТПРАВКА EMAIL МАСТЕРУ ПРИ МОДЕРАЦИИ (из 3.6)
        // ============================================================
        $master_email = isset($master['email']) ? $master['email'] : '';
        if (!empty($master_email)) {
            // ИСПРАВЛЕНО (SHA-256): stored-значение — хэш, в e-mail подставляется
            // одноразовый входной креденшел (ссылка активна 24 часа).
            $dashboard_token = function_exists('csc_issue_master_login_token')
                ? csc_issue_master_login_token($mid, $masters)
                : '';
            $dashboard_url = $dashboard_token !== ''
                ? home_url('/?csc_master_dashboard=1&mid=' . urlencode($mid) . '&token=' . urlencode($dashboard_token))
                : home_url('/?csc_master_dashboard=1');

            if ($action === 'approve') {
                $subject = '✅ Ваша заявка одобрена!';
                $message = "Здравствуйте, {$master['name']}!\n\n";
                $message .= "Ваша заявка на регистрацию мастера успешно одобрена.\n";
                $message .= "Теперь вы можете начать принимать заказы.\n\n";
                $message .= "Ссылка на личный кабинет:\n{$dashboard_url}\n\n";
                $message .= "Сохраните эту ссылку - она нужна для входа.\n";
                $message .= "Не забудьте настроить расписание работы в кабинете.";
                wp_mail($master_email, $subject, $message);
            } elseif ($action === 'reject') {
                $subject = '❌ Заявка отклонена';
                $reject_reason = !empty($reason) ? $reason : 'Причина не указана';
                $message = "Здравствуйте, {$master['name']}!\n\n";
                $message .= "К сожалению, ваша заявка на регистрацию мастера была отклонена.\n\n";
                $message .= "Причина: " . $reject_reason . "\n\n";
                $message .= "Вы можете исправить указанные замечания и подать заявку повторно.";
                wp_mail($master_email, $subject, $message);
            } elseif ($action === 'block') {
                $subject = '🚫 Аккаунт заблокирован';
                $block_reason = !empty($reason) ? $reason : 'Нарушение правил';
                $penalties_total = isset($master['penalties_total']) ? intval($master['penalties_total']) : 0;
                $penalties_amount = isset($master['penalties_amount']) ? floatval($master['penalties_amount']) : 0;
                $message = "Здравствуйте, {$master['name']}!\n\n";
                $message .= "Ваш аккаунт был заблокирован.\n";
                $message .= "Причина: " . $block_reason . "\n";
                if ($penalties_total > 0) {
                    $message .= "Всего штрафов: " . $penalties_total . "\n";
                    $message .= "Сумма штрафов: " . number_format($penalties_amount, 0, '.', ' ') . " ₽\n";
                }
                $message .= "\nДля разблокировки свяжитесь с администрацией.";
                wp_mail($master_email, $subject, $message);
            } elseif ($action === 'unblock') {
                $subject = '🔓 Аккаунт разблокирован';
                $message = "Здравствуйте, {$master['name']}!\n\n";
                $message .= "Ваш аккаунт успешно разблокирован.\n";
                $message .= "Вы снова можете принимать заказы.\n\n";
                $message .= "Ссылка на личный кабинет:\n{$dashboard_url}";
                wp_mail($master_email, $subject, $message);
            }
        }
        // ============================================================
        // КОНЕЦ ОТПРАВКИ EMAIL
        // ============================================================

        // ============================================================
        // ВОССТАНОВЛЕНО 6.2: ВНУТРИКАБИНЕТНОЕ УВЕДОМЛЕНИЕ МАСТЕРУ (из 3.6)
        // ============================================================
        if (function_exists('csc_add_master_notification')) {
            $notif_titles = array(
                'approve' => '✅ Заявка одобрена!',
                'reject'  => '❌ Заявка отклонена',
                'block'   => '🚫 Аккаунт заблокирован',
                'unblock' => '🔓 Аккаунт разблокирован',
            );
            $notif_messages = array(
                'approve' => 'Ваша заявка успешно прошла модерацию. Теперь вы можете принимать заказы.',
                'reject'  => 'Причина: ' . (!empty($reason) ? $reason : 'Не указана'),
                'block'   => 'Причина: ' . (!empty($reason) ? $reason : 'Нарушение правил') . '. Свяжитесь с администрацией.',
                'unblock' => 'Вы снова можете принимать заказы.',
            );
            csc_add_master_notification(
                $mid,
                $action,
                $notif_titles[$action] ?? 'Статус изменён',
                $notif_messages[$action] ?? '',
                array('action' => $action, 'reason' => $reason)
            );
        }
        // ============================================================
        // КОНЕЦ ВНУТРИКАБИНЕТНОГО УВЕДОМЛЕНИЯ
        // ============================================================

        // Уведомление мастеру по email (если есть функция модерации)
        if (function_exists('csc_send_moderation_email')) {
            csc_send_moderation_email($mid, $action, $reason);
        }

        // Логирование в систему уведомлений
        if (function_exists('csc_log_notification')) {
            $labels = array(
                'approve' => '✅ Одобрен',
                'reject'  => '❌ Отклонён',
                'block'   => '🚫 Заблокирован',
                'unblock' => '🔓 Разблокирован',
            );
            csc_log_notification(
                'system',
                'master',
                $mid,
                'web',
                'Модерация: ' . ($labels[$action] ?? $action),
                "Мастер {$master['name']}: " . ($labels[$action] ?? $action) . (!empty($reason) ? ". Причина: {$reason}" : ''),
                array('action' => $action, 'reason' => $reason, 'old_status' => $old_status)
            );
        }

        wp_send_json_success(array(
            'message' => 'Статус мастера обновлён',
            'new_status' => $master['status'],
        ));
    }
}

// ============================================================
// 6. МАССОВОЕ ОДОБРЕНИЕ ВСЕХ СУЩЕСТВУЮЩИХ МАСТЕРОВ
//    ВОССТАНОВЛЕНО 6.2: внутрикабинетные уведомления при массовом одобрении (из 3.6)
// ============================================================
add_action('wp_ajax_csc_bulk_approve_masters', 'csc_bulk_approve_masters_ajax');

if (!function_exists('csc_bulk_approve_masters_ajax')) {
    function csc_bulk_approve_masters_ajax() {
        if (!current_user_can('manage_options')) {
            wp_send_json_error(array('message' => 'Доступ запрещен'));
        }
        if (!check_ajax_referer('csc_masters_action', '_wpnonce', false)) {
            wp_send_json_error(array('message' => 'Ошибка безопасности'));
        }

        $masters = csc_get_masters(true);
        $approved_count = 0;

        foreach ($masters as $mid => &$master) {
            $status = $master['status'] ?? 'active';
            if ($status === 'pending') {
                $master['status'] = 'active';
                $master['is_online'] = true;
                $master['approved_at'] = current_time('mysql');

                // Запись в историю модерации
                if (!isset($master['moderation_history']) || !is_array($master['moderation_history'])) {
                    $master['moderation_history'] = array();
                }
                $master['moderation_history'][] = array(
                    'action' => 'approve',
                    'date'   => current_time('mysql'),
                    'by'     => wp_get_current_user()->user_login,
                    'note'   => 'Массовое одобрение',
                );

                // ============================================================
                // ВОССТАНОВЛЕНО 6.2: ВНУТРИКАБИНЕТНОЕ УВЕДОМЛЕНИЕ ПРИ МАССОВОМ ОДОБРЕНИИ (из 3.6)
                // ============================================================
                if (function_exists('csc_add_master_notification')) {
                    csc_add_master_notification(
                        $mid,
                        'approve',
                        '✅ Заявка одобрена!',
                        'Ваша заявка успешно прошла модерацию. Теперь вы можете принимать заказы.',
                        array('action' => 'approve', 'reason' => 'Массовое одобрение')
                    );
                }

                $approved_count++;
            }
        }
        unset($master);

        if ($approved_count > 0) {
            csc_save_masters($masters);
        }

        wp_send_json_success(array(
            'message' => "Одобрено мастеров: {$approved_count}",
            'count' => $approved_count,
        ));
    }
}
// ============================================================
// 7. AJAX: ПОЛУЧЕНИЕ ПОЛНОЙ КАРТОЧКИ МАСТЕРА (ПРОСМОТР)
//    ВОССТАНОВЛЕНО 6.2: маппинги зон/опыта/документов из 3.6
//    СОХРАНЕНО 6.1: SQL-оптимизация статистики при миграции в БД
// ============================================================
add_action('wp_ajax_csc_get_master_details', 'csc_get_master_details_ajax');

if (!function_exists('csc_get_master_details_ajax')) {
    function csc_get_master_details_ajax() {
        if (!current_user_can('manage_options')) {
            wp_send_json_error(array('message' => 'Доступ запрещен'));
        }
        if (!check_ajax_referer('csc_masters_action', '_wpnonce', false)) {
            wp_send_json_error(array('message' => 'Ошибка безопасности'));
        }

        $mid = sanitize_text_field($_POST['mid'] ?? '');
        if (empty($mid)) {
            wp_send_json_error(array('message' => 'ID мастера не указан'));
        }

        $masters = csc_get_masters(true);
        if (!isset($masters[$mid])) {
            wp_send_json_error(array('message' => 'Мастер не найден'));
        }

        $master = $masters[$mid];

        // ============================================================
        // ВОССТАНОВЛЕНО 6.2: МАОППИНГ ЗОН ИЗ ID В ЧЕЛОВЕКОЧИТАЕМЫЕ НАЗВАНИЯ (из 3.6)
        // ============================================================
        $zones_raw = isset($master['zones']) ? (array)$master['zones'] : array();
        $zones_display = array();
        if (!empty($master['city']) && function_exists('csc_get_city_zones')) {
            $city_zones = csc_get_city_zones($master['city'], false);
            foreach ($zones_raw as $zid) {
                if (isset($city_zones[$zid])) {
                    $zones_display[] = $city_zones[$zid]['name'];
                } else {
                    $zones_display[] = $zid; // fallback: показываем ID если название не найдено
                }
            }
        } else {
            $zones_display = $zones_raw;
        }

        // ============================================================
        // ВОССТАНОВЛЕНО 6.2: МАОППИНГ ОПЫТА РАБОТЫ СО СКЛОНЕНИЕМ (из 3.6)
        // ============================================================
        $experience_val = __('Не указано', 'csc');
        if (isset($master['experience_years']) && $master['experience_years'] > 0) {
            $exp_years = intval($master['experience_years']);
            // Склонение через _n() с корректным числовым аргументом:
            // 1 год / 2-4 года / 5+ лет. Для десятков (11-15) и т.п. используем
            // остаток от деления на 10 — стандартный приём для славянских языков.
            $plural_n = ($exp_years % 100 >= 11 && $exp_years % 100 <= 14) ? 5 : ($exp_years % 10 == 1 ? 1 : ($exp_years % 10 >= 2 && $exp_years % 10 <= 4 ? 2 : 5));
            $experience_val = $exp_years . ' ' . _n('год', 'лет', $plural_n, 'csc');
            if ($plural_n === 2) {
                $experience_val = $exp_years . ' ' . _n('год', 'года', 2, 'csc');
            }
        } elseif (!empty($master['experience'])) {
            $experience_val = $master['experience'];
        }

        // ============================================================
        // Сбор данных мастера
        // ============================================================
        $master_data = array(
            'id'            => $mid,
            'name'          => $master['name'] ?? 'Без имени',
            'phone'         => $master['phone'] ?? '',
            'email'         => $master['email'] ?? '',
            'tg_chat_id'    => $master['tg_chat_id'] ?? '',
            'color'         => $master['color'] ?? '#3b82f6',
            // МАОППИНГ photo → avatar (из 3.6)
            'avatar'        => $master['photo'] ?? ($master['avatar'] ?? ''),
            'status'        => $master['status'] ?? 'active',
            'is_online'     => isset($master['is_online']) ? !empty($master['is_online']) : true,
            'active'        => !empty($master['active']),
            // ВОССТАНОВЛЕНО 6.2: поля города и расписания (из 3.6)
            'city'          => $master['city'] ?? '',
            'city_name'     => $master['city_name'] ?? '',
            'created_at'    => $master['created_at'] ?? '',
            'approved_at'   => $master['approved_at'] ?? '',
            'last_login'    => $master['last_login'] ?? '',
            'about'         => $master['about'] ?? 'Информация не указана',
            // ВОССТАНОВЛЕНО 6.2: опыт с маппингом
            'experience'    => $experience_val,
            'specializations' => $master['specializations'] ?? array(),
            // ВОССТАНОВЛЕНО 6.2: зоны с названиями
            'zones'         => $zones_display,
            'work_hours_start' => $master['work_hours_start'] ?? 9,
            'work_hours_end'   => $master['work_hours_end'] ?? 18,
            'work_days'     => isset($master['work_days']) ? (array)$master['work_days'] : array(1,2,3,4,5),
            // ВОССТАНОВЛЕНО 6.2: детальное расписание schedule (из 3.6)
            'schedule'      => isset($master['schedule']) ? (array)$master['schedule'] : array(),
            'comm_type'     => $master['comm_type'] ?? 'percent',
            'comm_val'      => $master['comm_val'] ?? 0,
            'dashboard_token' => $master['dashboard_token'] ?? '',
            'penalties_total'  => isset($master['penalties_total']) ? intval($master['penalties_total']) : 0,
            'penalties_amount' => isset($master['penalties_amount']) ? floatval($master['penalties_amount']) : 0,
            'earned_total'     => isset($master['earned_total']) ? floatval($master['earned_total']) : 0,
            'rejection_reason' => $master['rejection_reason'] ?? '',
            'block_reason'     => $master['block_reason'] ?? '',
            // НОВОЕ 6.0: Поля зоны обслуживания для карты
            'service_address' => $master['service_address'] ?? '',
            'service_lat'     => isset($master['service_lat']) ? floatval($master['service_lat']) : null,
            'service_lng'     => isset($master['service_lng']) ? floatval($master['service_lng']) : null,
            'service_radius'  => isset($master['service_radius']) ? intval($master['service_radius']) : 10,
        );

        // ============================================================
        // ВОССТАНОВЛЕНО 6.2: Документы из photo + document_photo + documents (из 3.6)
        // + тип документа из 6.1
        // ============================================================
        $documents = array();
        if (!empty($master['photo'])) {
            $documents[] = array(
                'name' => 'Фото мастера',
                'url'  => $master['photo'],
                'type' => 'photo'
            );
        }
        if (!empty($master['document_photo'])) {
            $documents[] = array(
                'name' => 'Документ (' . ($master['document_type'] ?? 'паспорт') . ')',
                'url'  => $master['document_photo'],
                'type' => 'identity'
            );
        }
        if (isset($master['documents']) && is_array($master['documents'])) {
            foreach ($master['documents'] as $doc) {
                $documents[] = $doc;
            }
        }
        $master_data['documents'] = $documents;

        // ============================================================
        // Статистика заказов
        // СОХРАНЕНО 6.1: Оптимизация через SQL при миграции в БД
        // ============================================================
        $stats = array(
            'total' => 0,
            'completed' => 0,
            'cancelled' => 0,
            'rating' => 0,
            'rating_count' => 0,
            'recent_orders' => array()
        );

        // ИСПРАВЛЕНО 6.1: Оптимизация — используем SQL при миграции в БД
        $table_exists = get_option('csc_orders_migrated', false);
        $master_orders = array();

        if ($table_exists) {
            global $wpdb;
            $table = $wpdb->prefix . 'csc_orders';

            // Агрегация статистики одним запросом
            $stats_row = $wpdb->get_row($wpdb->prepare("
                SELECT 
                    COUNT(*) as total,
                    SUM(CASE WHEN status IN ('completed') THEN 1 ELSE 0 END) as completed,
                    SUM(CASE WHEN status IN ('cancelled', 'canceled') THEN 1 ELSE 0 END) as cancelled
                FROM {$table} 
                WHERE master_id = %s
            ", $mid), ARRAY_A);

            if ($stats_row) {
                $stats['total'] = intval($stats_row['total'] ?? 0);
                $stats['completed'] = intval($stats_row['completed'] ?? 0);
                $stats['cancelled'] = intval($stats_row['cancelled'] ?? 0);
            }

            // Последние 10 заказов
            $results = $wpdb->get_results($wpdb->prepare("
                SELECT order_number, name, phone, date, time, status, services 
                FROM {$table} 
                WHERE master_id = %s 
                ORDER BY created_at DESC 
                LIMIT 10
            ", $mid), ARRAY_A);

            if ($results) {
                foreach ($results as $row) {
                    $services = json_decode($row['services'] ?? '[]', true) ?: [];
                    $service_str = '';
                    if (!empty($services) && is_array($services)) {
                        $service_str = is_string($services[0] ?? null) 
                            ? implode(', ', array_slice($services, 0, 2))
                            : 'Услуги';
                    }

                    $master_orders[] = array(
                        'order_number' => $row['order_number'],
                        'client_name'  => $row['name'],
                        'phone'        => $row['phone'],
                        'date'         => $row['date'],
                        'time'         => $row['time'],
                        'status'       => $row['status'],
                        'service'      => $service_str
                    );
                }
            }
        } else {
            // Фолбэк: читаем из wp_options
            if (function_exists('csc_get_all_orders')) {
                $all_orders = csc_get_all_orders();
            } else {
                $all_orders = get_option('csc_all_orders', array());
            }

            $completed_statuses = array(CSC_STATUS_COMPLETED, 'completed', CSC_STATUS_COMPLETED);
            $cancelled_statuses = array(CSC_STATUS_CANCELLED, 'rejected'); // 3.5: латинские ключи; 'отклонено' покрывается read-normalizer'ом

            $master_orders = array();
            foreach ($all_orders as $order) {
                $order_master = $order['master_id'] ?? '';
                if ($order_master !== $mid) continue;

                $master_orders[] = $order;
                $stats['total']++;

                $status = $order['status'] ?? '';
                if (in_array($status, $completed_statuses)) {
                    $stats['completed']++;
                } elseif (in_array($status, $cancelled_statuses)) {
                    $stats['cancelled']++;
                }
            }

            // Сортировка последних 10 заказов
            // ИСПРАВЛЕНО 6.1: Используем DateTime для корректного сравнения
            usort($master_orders, function($a, $b) {
                try {
                    $dt_a = new DateTime(($a['date'] ?? '') . ' ' . ($a['time'] ?? '00:00'));
                    $dt_b = new DateTime(($b['date'] ?? '') . ' ' . ($b['time'] ?? '00:00'));
                    return $dt_b->getTimestamp() - $dt_a->getTimestamp();
                } catch (Exception $e) {
                    return 0;
                }
            });

            // Форматируем для JS
            $formatted_orders = array();
            foreach (array_slice($master_orders, 0, 10) as $o) {
                $services = $o['services'] ?? [];
                $service_str = '';
                if (!empty($services) && is_array($services)) {
                    $service_str = is_string($services[0] ?? null) 
                        ? implode(', ', array_slice($services, 0, 2))
                        : 'Услуги';
                }

                $formatted_orders[] = array(
                    'order_number' => $o['order_number'],
                    'client_name'  => $o['name'] ?? '',
                    'phone'        => $o['phone'] ?? '',
                    'date'         => $o['date'] ?? '',
                    'time'         => $o['time'] ?? '',
                    'status'       => $o['status'] ?? '',
                    'service'      => $service_str
                );
            }
            $master_orders = $formatted_orders;
        }

        $stats['recent_orders'] = $master_orders;

        // === Рейтинг ===
        if (function_exists('csc_get_master_rating')) {
            $rating_data = csc_get_master_rating($mid);
            $stats['rating'] = $rating_data['avg'] ?? 0;
            $stats['rating_count'] = $rating_data['count'] ?? 0;
        }

        $master_data['stats'] = $stats;

        // === Отзывы ===
        $reviews = array();
        if (function_exists('csc_get_master_reviews')) {
            $reviews = csc_get_master_reviews($mid);
            if (!is_array($reviews)) $reviews = array();
        }
        $master_data['reviews'] = $reviews;

        // === История модерации ===
        $history = array();
        if (isset($master['moderation_history']) && is_array($master['moderation_history'])) {
            $history = $master['moderation_history'];
        } else {
            // Восстанавливаем базовую историю из временных меток
            if (!empty($master['created_at'])) {
                $history[] = array(
                    'action' => 'register',
                    'date' => $master['created_at'],
                    'by' => 'system',
                    'note' => 'Регистрация мастера'
                );
            }
            if (!empty($master['approved_at']) && ($master['status'] ?? 'active') === 'active') {
                $history[] = array(
                    'action' => 'approve',
                    'date' => $master['approved_at'],
                    'by' => 'admin',
                    'note' => 'Автоматическое одобрение при миграции'
                );
            }
        }
        $master_data['history'] = $history;

        // === История выплат ===
        $payouts = array();
        if (isset($master['payouts']) && is_array($master['payouts'])) {
            $payouts = $master['payouts'];
        }
        $master_data['payouts'] = $payouts;

        wp_send_json_success(array(
            'master' => $master_data
        ));
    }
}