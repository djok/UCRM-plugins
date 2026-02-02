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
                                    <!-- Organization Filter -->
                                    <div class="col-md-2 mb-2">
                                        <label class="mb-0" for="frm-organization"><small>Organization:</small></label>
                                        <select name="organization" id="frm-organization" class="form-control form-control-sm">
                                            <option value="">All Organizations</option>
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

                                    <!-- Format Selection -->
                                    <div class="col-md-1 mb-2">
                                        <label class="mb-0" for="frm-format"><small>Format:</small></label>
                                        <select name="format" id="frm-format" class="form-control form-control-sm">
                                            <option value="xlsx">XLSX</option>
                                            <option value="csv">CSV</option>
                                        </select>
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

            <!-- Help/Info Section -->
            <div class="row">
                <div class="col-12">
                    <div class="card">
                        <div class="card-body">
                            <h6>Export Details</h6>
                            <p class="small text-muted mb-2">
                                This export includes all payments within the selected period along with
                                their linked invoice details. Each row shows how much of each payment
                                was applied to specific invoices.
                            </p>
                            <p class="small text-muted mb-0">
                                <strong>Columns:</strong> Organization, Payment ID, Provider Payment ID, Payment Date,
                                Client ID, Client Type, Client Name, Company Name, Company ID (EIK), VAT ID, Personal ID,
                                Payment Method, Currency, Total Payment Amount, Credit Amount,
                                Invoice Number, Invoice Date, Invoice Total, Invoice Status,
                                Amount Applied to Invoice, Note
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
        </script>
    </body>
</html>
