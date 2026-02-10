<!doctype html>
<html lang="en">
    <head>
        <meta charset="utf-8">
        <meta http-equiv="x-ua-compatible" content="ie=edge">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <title>Payment Invoice Export</title>
        <link rel="stylesheet" href="<?php echo rtrim(htmlspecialchars($ucrmPublicUrl, ENT_QUOTES), '/'); ?>/assets/fonts/lato/lato.css">
        <link rel="stylesheet" href="https://stackpath.bootstrapcdn.com/bootstrap/4.1.3/css/bootstrap.min.css" integrity="sha384-MCw98/SFnGE8fJT3GXwEOngsV7Zt27NXFoaoApmYm81iuXoPkFOJwJ8ERdknLPMO" crossorigin="anonymous">
        <link rel="stylesheet" href="public/main.css">
    </head>
    <body>
        <div id="header">
            <h1>Payment Invoice Export</h1>
        </div>
        <div id="content" class="container container-fluid ml-0 mr-0">
            <div class="row mb-4">
                <div class="col-12">
                    <div class="card">
                        <div class="card-body">
                            <form id="export-form" method="post">
                                <div class="form-row align-items-end">
                                    <!-- Organization Filter (Required) -->
                                    <div class="col-md-2 mb-2">
                                        <label class="mb-0" for="frm-organization"><small>Organization: <span class="text-danger">*</span></small></label>
                                        <select name="organization" id="frm-organization" class="form-control form-control-sm" required>
                                            <option value="">-- Select Organization --</option>
                                            <?php foreach ($organizations as $organization): ?>
                                                <option value="<?php echo $organization['id']; ?>">
                                                    <?php echo htmlspecialchars($organization['name'], ENT_QUOTES); ?>
                                                </option>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>

                                    <!-- Period Selection -->
                                    <div class="col-md-2 mb-2">
                                        <label class="mb-0" for="frm-period"><small>Period:</small></label>
                                        <select name="period" id="frm-period" class="form-control form-control-sm">
                                            <option value="current_month">Current Month</option>
                                            <option value="previous_month">Previous Month</option>
                                            <option value="current_quarter">Current Quarter</option>
                                            <option value="previous_quarter">Previous Quarter</option>
                                            <option value="current_year">Current Year</option>
                                            <option value="previous_year">Previous Year</option>
                                            <option value="custom">Custom Range</option>
                                        </select>
                                    </div>

                                    <!-- Custom Date From -->
                                    <div class="col-md-2 mb-2 custom-date-field" style="display: none;">
                                        <label class="mb-0" for="frm-dateFrom"><small>From:</small></label>
                                        <input type="date" name="dateFrom" id="frm-dateFrom"
                                               class="form-control form-control-sm" placeholder="YYYY-MM-DD">
                                    </div>

                                    <!-- Custom Date To -->
                                    <div class="col-md-2 mb-2 custom-date-field" style="display: none;">
                                        <label class="mb-0" for="frm-dateTo"><small>To:</small></label>
                                        <input type="date" name="dateTo" id="frm-dateTo"
                                               class="form-control form-control-sm" placeholder="YYYY-MM-DD">
                                    </div>

                                    <!-- Export Button -->
                                    <div class="col-auto ml-auto mb-2">
                                        <button type="submit" class="btn btn-primary btn-sm pl-4 pr-4">
                                            Export
                                        </button>
                                    </div>
                                </div>
                            </form>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Progress Bar (shown during export) -->
            <div class="row mb-4" id="progress-section" style="display: none;">
                <div class="col-12">
                    <div class="card border-primary">
                        <div class="card-header bg-primary text-white py-2">
                            <strong id="progress-title">Генериране на експорт...</strong>
                        </div>
                        <div class="card-body">
                            <div class="progress mb-2" style="height: 24px;">
                                <div id="progress-bar" class="progress-bar progress-bar-striped progress-bar-animated bg-primary"
                                     role="progressbar" style="width: 0%;" aria-valuenow="0" aria-valuemin="0" aria-valuemax="100">
                                    0%
                                </div>
                            </div>
                            <p class="mb-0 text-muted small" id="progress-message">Стартиране...</p>
                        </div>
                    </div>
                </div>
            </div>

            <?php if (!empty($controlsData)): ?>
            <!-- Controls / Export Results -->
            <div class="row mb-4">
                <div class="col-12">
                    <div class="card border-success">
                        <div class="card-header bg-success text-white py-2">
                            <strong>Резултат от експорта</strong>
                        </div>
                        <div class="card-body">
                            <div class="row">
                                <div class="col-md-6">
                                    <h6>Контроли</h6>
                                    <table class="table table-sm table-bordered mb-0">
                                        <tbody>
                                            <tr>
                                                <td>Брой фактури</td>
                                                <td class="font-weight-bold"><?php echo $controlsData['invoiceCount']; ?></td>
                                            </tr>
                                            <tr>
                                                <td>Най-малък номер</td>
                                                <td class="font-weight-bold"><?php echo htmlspecialchars($controlsData['minNumber'], ENT_QUOTES); ?></td>
                                            </tr>
                                            <tr>
                                                <td>Най-голям номер</td>
                                                <td class="font-weight-bold"><?php echo htmlspecialchars($controlsData['maxNumber'], ENT_QUOTES); ?></td>
                                            </tr>
                                            <tr>
                                                <td>Брой кредитни известия</td>
                                                <td class="font-weight-bold"><?php echo $controlsData['creditNoteCount']; ?></td>
                                            </tr>
                                            <tr class="table-info">
                                                <td>Стойност без ДДС</td>
                                                <td class="font-weight-bold"><?php echo number_format($controlsData['exportedUntaxedSum'], 2, '.', ' '); ?></td>
                                            </tr>
                                            <tr class="table-info">
                                                <td>ДДС</td>
                                                <td class="font-weight-bold"><?php echo number_format($controlsData['exportedVatSum'], 2, '.', ' '); ?></td>
                                            </tr>
                                            <tr class="table-info">
                                                <td>Общо с ДДС</td>
                                                <td class="font-weight-bold"><?php echo number_format($controlsData['exportedUntaxedSum'] + $controlsData['exportedVatSum'], 2, '.', ' '); ?></td>
                                            </tr>
                                        </tbody>
                                    </table>
                                </div>
                                <div class="col-md-6">
                                    <h6>Пропуснати номера на фактури</h6>
                                    <?php if (empty($controlsData['missingNumbers'])): ?>
                                        <p class="text-success mb-0">Няма пропуснати номера.</p>
                                    <?php else: ?>
                                        <div class="alert alert-warning py-2 mb-0" style="max-height: 150px; overflow-y: auto;">
                                            <?php echo htmlspecialchars(implode(', ', $controlsData['missingNumbers']), ENT_QUOTES); ?>
                                        </div>
                                    <?php endif; ?>
                                </div>
                            </div>
                            <?php if (!empty($controlsData['zeroTotalInvoices'])): ?>
                            <div class="row mt-3">
                                <div class="col-12">
                                    <h6>Нулеви фактури (изключени от експорта): <?php echo count($controlsData['zeroTotalInvoices']); ?> бр.</h6>
                                    <div style="max-height: 200px; overflow-y: auto;">
                                        <table class="table table-sm table-bordered table-hover mb-0">
                                            <thead class="thead-light">
                                                <tr>
                                                    <th>Номер</th>
                                                    <th>Дата</th>
                                                    <th>Партньор</th>
                                                    <th>ЕИК</th>
                                                    <th>ИН по ДДС</th>
                                                </tr>
                                            </thead>
                                            <tbody>
                                                <?php foreach ($controlsData['zeroTotalInvoices'] as $zi): ?>
                                                <tr>
                                                    <td><?php echo htmlspecialchars($zi['number'], ENT_QUOTES); ?></td>
                                                    <td><?php echo htmlspecialchars($zi['date'], ENT_QUOTES); ?></td>
                                                    <td><?php echo htmlspecialchars($zi['clientName'], ENT_QUOTES); ?></td>
                                                    <td><?php echo htmlspecialchars($zi['eik'], ENT_QUOTES); ?></td>
                                                    <td><?php echo htmlspecialchars($zi['vatId'], ENT_QUOTES); ?></td>
                                                </tr>
                                                <?php endforeach; ?>
                                            </tbody>
                                        </table>
                                    </div>
                                </div>
                            </div>
                            <?php endif; ?>
                            <hr class="my-3">
                            <a href="?download=<?php echo urlencode($controlsData['downloadFile']); ?>" class="btn btn-success btn-sm">
                                Изтегли ZIP архива
                            </a>
                        </div>
                    </div>
                </div>
            </div>
            <?php endif; ?>

            <!-- Help/Info Section -->
            <div class="row">
                <div class="col-12">
                    <div class="card">
                        <div class="card-body">
                            <h6>Export Details</h6>
                            <p class="small text-muted mb-2">
                                This export generates a ZIP archive containing two reports in both CSV and XLSX formats:
                            </p>
                            <p class="small text-muted mb-2">
                                <strong>1. Payments Report:</strong> All payments within the selected period with linked invoice details.
                            </p>
                            <p class="small text-muted mb-2">
                                <strong>2. Sales Report:</strong> All invoices and credit notes within the selected period
                                (excluding proforma invoices).
                            </p>
                            <p class="small text-muted mb-0">
                                <strong>Note:</strong> Organization selection is required.
                            </p>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <script>
            // Toggle custom date fields based on period selection
            document.getElementById('frm-period').addEventListener('change', function() {
                var customFields = document.querySelectorAll('.custom-date-field');
                var display = this.value === 'custom' ? 'block' : 'none';
                customFields.forEach(function(field) {
                    field.style.display = display;
                });
            });

            // AJAX export with progress polling
            (function() {
                var form = document.getElementById('export-form');
                var progressSection = document.getElementById('progress-section');
                var progressBar = document.getElementById('progress-bar');
                var progressMessage = document.getElementById('progress-message');
                var resultsSection = document.getElementById('results-section');
                var pollTimer = null;

                function setProgress(step, total, message) {
                    var pct = total > 0 ? Math.round((step / total) * 100) : 0;
                    progressBar.style.width = pct + '%';
                    progressBar.textContent = pct + '%';
                    progressBar.setAttribute('aria-valuenow', pct);
                    if (message) {
                        progressMessage.textContent = message;
                    }
                }

                function startPolling() {
                    pollTimer = setInterval(function() {
                        fetch('?action=progress', { cache: 'no-store' })
                            .then(function(r) { return r.json(); })
                            .then(function(data) {
                                setProgress(data.step, data.total, data.message);
                            })
                            .catch(function() {});
                    }, 1500);
                }

                function stopPolling() {
                    if (pollTimer) {
                        clearInterval(pollTimer);
                        pollTimer = null;
                    }
                }

                function renderResults(data) {
                    if (resultsSection) {
                        resultsSection.remove();
                    }

                    var html = '<div class="row mb-4" id="results-section"><div class="col-12">' +
                        '<div class="card border-success">' +
                        '<div class="card-header bg-success text-white py-2"><strong>Резултат от експорта</strong></div>' +
                        '<div class="card-body"><div class="row"><div class="col-md-6">' +
                        '<h6>Контроли</h6><table class="table table-sm table-bordered mb-0"><tbody>' +
                        '<tr><td>Брой фактури</td><td class="font-weight-bold">' + data.invoiceCount + '</td></tr>' +
                        '<tr><td>Най-малък номер</td><td class="font-weight-bold">' + escHtml(data.minNumber) + '</td></tr>' +
                        '<tr><td>Най-голям номер</td><td class="font-weight-bold">' + escHtml(data.maxNumber) + '</td></tr>' +
                        '<tr><td>Брой кредитни известия</td><td class="font-weight-bold">' + data.creditNoteCount + '</td></tr>' +
                        '<tr class="table-info"><td>Стойност без ДДС</td><td class="font-weight-bold">' + fmtNum(data.exportedUntaxedSum) + '</td></tr>' +
                        '<tr class="table-info"><td>ДДС</td><td class="font-weight-bold">' + fmtNum(data.exportedVatSum) + '</td></tr>' +
                        '<tr class="table-info"><td>Общо с ДДС</td><td class="font-weight-bold">' + fmtNum(data.exportedUntaxedSum + data.exportedVatSum) + '</td></tr>' +
                        '</tbody></table></div>' +
                        '<div class="col-md-6"><h6>Пропуснати номера на фактури</h6>';

                    if (!data.missingNumbers || data.missingNumbers.length === 0) {
                        html += '<p class="text-success mb-0">Няма пропуснати номера.</p>';
                    } else {
                        html += '<div class="alert alert-warning py-2 mb-0" style="max-height:150px;overflow-y:auto;">' +
                            escHtml(data.missingNumbers.join(', ')) + '</div>';
                    }
                    html += '</div></div>';

                    // Zero-total invoices table
                    if (data.zeroTotalInvoices && data.zeroTotalInvoices.length > 0) {
                        html += '<div class="row mt-3"><div class="col-12">' +
                            '<h6>Нулеви фактури (изключени от експорта): ' + data.zeroTotalInvoices.length + ' бр.</h6>' +
                            '<div style="max-height:200px;overflow-y:auto;">' +
                            '<table class="table table-sm table-bordered table-hover mb-0">' +
                            '<thead class="thead-light"><tr><th>Номер</th><th>Дата</th><th>Партньор</th><th>ЕИК</th><th>ИН по ДДС</th></tr></thead><tbody>';
                        data.zeroTotalInvoices.forEach(function(zi) {
                            html += '<tr><td>' + escHtml(zi.number) + '</td><td>' + escHtml(zi.date) + '</td>' +
                                '<td>' + escHtml(zi.clientName) + '</td><td>' + escHtml(zi.eik) + '</td>' +
                                '<td>' + escHtml(zi.vatId) + '</td></tr>';
                        });
                        html += '</tbody></table></div></div></div>';
                    }

                    html += '<hr class="my-3"><a href="?download=' + encodeURIComponent(data.downloadFile) +
                        '" class="btn btn-success btn-sm">Изтегли ZIP архива</a>' +
                        '</div></div></div></div>';

                    progressSection.insertAdjacentHTML('afterend', html);
                    resultsSection = document.getElementById('results-section');
                }

                function escHtml(s) {
                    if (s === null || s === undefined) return '';
                    var d = document.createElement('div');
                    d.textContent = String(s);
                    return d.innerHTML;
                }

                function fmtNum(n) {
                    return (n || 0).toFixed(2).replace(/\B(?=(\d{3})+(?!\d))/g, ' ');
                }

                form.addEventListener('submit', function(e) {
                    e.preventDefault();

                    // Validate
                    var org = form.querySelector('[name="organization"]');
                    if (!org.value) {
                        org.focus();
                        return;
                    }

                    // Show progress, hide old results
                    progressSection.style.display = '';
                    setProgress(0, 10, 'Стартиране...');
                    var oldResults = document.getElementById('results-section');
                    if (oldResults) oldResults.remove();

                    // Disable submit
                    var btn = form.querySelector('button[type="submit"]');
                    btn.disabled = true;
                    btn.textContent = 'Обработка...';

                    startPolling();

                    // Submit via AJAX
                    var formData = new FormData(form);
                    fetch('', {
                        method: 'POST',
                        headers: { 'X-Requested-With': 'XMLHttpRequest' },
                        body: formData
                    })
                    .then(function(r) { return r.json(); })
                    .then(function(resp) {
                        stopPolling();
                        if (resp.success) {
                            setProgress(10, 10, 'Готово!');
                            progressBar.classList.remove('progress-bar-animated');
                            progressBar.classList.replace('bg-primary', 'bg-success');
                            setTimeout(function() {
                                progressSection.style.display = 'none';
                                renderResults(resp.data);
                            }, 600);
                        } else {
                            setProgress(0, 10, 'Грешка: ' + (resp.error || 'Неизвестна грешка'));
                            progressBar.classList.remove('progress-bar-animated');
                            progressBar.classList.replace('bg-primary', 'bg-danger');
                        }
                    })
                    .catch(function(err) {
                        stopPolling();
                        setProgress(0, 10, 'Грешка при връзката: ' + err.message);
                        progressBar.classList.remove('progress-bar-animated');
                        progressBar.classList.replace('bg-primary', 'bg-danger');
                    })
                    .finally(function() {
                        btn.disabled = false;
                        btn.textContent = 'Export';
                    });
                });
            })();
        </script>
    </body>
</html>
