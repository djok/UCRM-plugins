<!doctype html>
<html lang="bg">
    <head>
        <meta charset="utf-8">
        <meta http-equiv="x-ua-compatible" content="ie=edge">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <title>Export Service Mapping</title>
        <link rel="stylesheet" href="<?php echo rtrim(htmlspecialchars($ucrmPublicUrl, ENT_QUOTES), '/'); ?>/assets/fonts/lato/lato.css">
        <link rel="stylesheet" href="https://stackpath.bootstrapcdn.com/bootstrap/4.1.3/css/bootstrap.min.css" integrity="sha384-MCw98/SFnGE8fJT3GXwEOngsV7Zt27NXFoaoApmYm81iuXoPkFOJwJ8ERdknLPMO" crossorigin="anonymous">
        <link rel="stylesheet" href="public/main.css">
    </head>
    <body>
        <div id="header">
            <h1>Export Service Mapping</h1>
        </div>
        <div id="content" class="container container-fluid ml-0 mr-0">

            <?php if (!empty($saved)): ?>
            <div class="row mb-3">
                <div class="col-12">
                    <div class="alert alert-success py-2 mb-0">Настройките са запазени успешно.</div>
                </div>
            </div>
            <?php endif; ?>

            <form method="post">
                <input type="hidden" name="action" value="save_mapping">

                <!-- Default Internet Label -->
                <div class="row mb-4">
                    <div class="col-12">
                        <div class="card">
                            <div class="card-body">
                                <h6>Общо име за Internet услуги</h6>
                                <p class="small text-muted mb-2">
                                    Това име ще се използва за всички Internet service plans, които нямат зададено индивидуално счетоводно име.
                                </p>
                                <div class="form-row align-items-end">
                                    <div class="col-md-4">
                                        <input type="text" name="internetLabel" class="form-control form-control-sm"
                                               value="<?php echo htmlspecialchars($mapping['internetLabel'] ?? '', ENT_QUOTES); ?>"
                                               placeholder="напр. Телекомуникационни услуги">
                                    </div>
                                    <div class="col-auto">
                                        <button type="button" class="btn btn-outline-secondary btn-sm" id="btn-apply-internet">
                                            Приложи към всички Internet планове
                                        </button>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Service Plans Table -->
                <div class="row mb-4">
                    <div class="col-12">
                        <div class="card">
                            <div class="card-body">
                                <h6>Услуги в системата</h6>
                                <p class="small text-muted mb-2">
                                    Задайте счетоводно име за всеки service plan. Празно поле = ще се използва оригиналното име от UCRM.
                                </p>
                                <table class="table table-sm table-bordered table-hover mb-0">
                                    <thead class="thead-light">
                                        <tr>
                                            <th style="width: 40px;">ID</th>
                                            <th style="width: 100px;">Тип</th>
                                            <th>Име в UCRM</th>
                                            <th>Счетоводно име (Наименование)</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($servicePlans as $plan): ?>
                                            <?php
                                                $planId = $plan['id'];
                                                $planType = $plan['servicePlanType'] ?? 'General';
                                                $isInternet = ($planType === 'Internet');
                                                $currentValue = $mapping['plans'][(string)$planId] ?? '';
                                            ?>
                                            <tr class="<?php echo $isInternet ? 'table-info' : ''; ?>">
                                                <td class="text-muted"><?php echo $planId; ?></td>
                                                <td>
                                                    <?php if ($isInternet): ?>
                                                        <span class="badge badge-info">Internet</span>
                                                    <?php else: ?>
                                                        <span class="badge badge-secondary"><?php echo htmlspecialchars($planType, ENT_QUOTES); ?></span>
                                                    <?php endif; ?>
                                                </td>
                                                <td><?php echo htmlspecialchars($plan['name'] ?? '', ENT_QUOTES); ?></td>
                                                <td>
                                                    <input type="text"
                                                           name="plans[<?php echo $planId; ?>]"
                                                           class="form-control form-control-sm <?php echo $isInternet ? 'internet-plan-input' : ''; ?>"
                                                           value="<?php echo htmlspecialchars($currentValue, ENT_QUOTES); ?>"
                                                           placeholder="<?php echo htmlspecialchars($plan['name'] ?? '', ENT_QUOTES); ?>">
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Surcharges Table -->
                <div class="row mb-4">
                    <div class="col-12">
                        <div class="card">
                            <div class="card-body">
                                <h6>Допълнителни такси (Surcharges)</h6>
                                <p class="small text-muted mb-2">
                                    Задайте счетоводно име за всяка допълнителна такса. Празно поле = ще се използва оригиналното име от UCRM.
                                </p>
                                <?php if (empty($surcharges)): ?>
                                    <p class="text-muted mb-0">Няма намерени допълнителни такси в системата.</p>
                                <?php else: ?>
                                <table class="table table-sm table-bordered table-hover mb-0">
                                    <thead class="thead-light">
                                        <tr>
                                            <th style="width: 40px;">ID</th>
                                            <th>Име в UCRM</th>
                                            <th>Счетоводно име (Наименование)</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($surcharges as $surcharge): ?>
                                            <?php
                                                $surchargeId = $surcharge['id'];
                                                $currentValue = $mapping['surcharges'][(string)$surchargeId] ?? '';
                                            ?>
                                            <tr>
                                                <td class="text-muted"><?php echo $surchargeId; ?></td>
                                                <td><?php echo htmlspecialchars($surcharge['name'] ?? '', ENT_QUOTES); ?></td>
                                                <td>
                                                    <input type="text"
                                                           name="surcharges[<?php echo $surchargeId; ?>]"
                                                           class="form-control form-control-sm"
                                                           value="<?php echo htmlspecialchars($currentValue, ENT_QUOTES); ?>"
                                                           placeholder="<?php echo htmlspecialchars($surcharge['name'] ?? '', ENT_QUOTES); ?>">
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Actions -->
                <div class="row mb-4">
                    <div class="col-12">
                        <button type="submit" class="btn btn-primary btn-sm pl-4 pr-4">Запази</button>
                        <a href="?" class="btn btn-outline-secondary btn-sm ml-2">Към експорта</a>
                    </div>
                </div>
            </form>
        </div>

        <script>
            // Apply internet label to all Internet plan inputs
            document.getElementById('btn-apply-internet').addEventListener('click', function() {
                var label = document.querySelector('input[name="internetLabel"]').value;
                document.querySelectorAll('.internet-plan-input').forEach(function(input) {
                    input.value = label;
                });
            });
        </script>
    </body>
</html>
