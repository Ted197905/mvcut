<!doctype html>
<html>
<head>
    <meta charset="UTF-8">
    <meta name="robots" content="noindex">

    <title><?= lang('Errors.whoops') ?></title>

    <style>
        <?= preg_replace('#[\r\n\t ]+#', ' ', file_get_contents(__DIR__ . DIRECTORY_SEPARATOR . 'debug.css')) ?>
    </style>
</head>
<body>

    <div class="container text-center">

        <h1 class="headline"><?= lang('Errors.whoops') ?></h1>

        <p class="lead"><?= lang('Errors.weHitASnag') ?></p>

        <p class="lead" style="font-size:14px;color:#888">오류 코드: <?= esc(\App\Libraries\AppLog::reqId()) ?> (관리자 로그에서 이 코드로 찾을 수 있습니다)</p>

    </div>

</body>

</html>
