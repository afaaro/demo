<?php /** @var \System\Library\View $view */ ?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>419 — Session expired</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
</head>
<body class="bg-light">
    <div class="container py-5">
        <div class="row justify-content-center">
            <div class="col-lg-6">
                <div class="text-center">
                    <h1 class="display-4 text-warning">419</h1>
                    <h2 class="h4 mb-3">Session expired</h2>
                    <p class="text-muted mb-4">
                        Your session timed out or your security token is no longer valid.
                        Please go back, refresh the page, and try again.
                    </p>
                    <a href="<?= escape(route_url('')) ?>" class="btn btn-primary">
                        Go to home page
                    </a>
                </div>
            </div>
        </div>
    </div>
</body>
</html>
