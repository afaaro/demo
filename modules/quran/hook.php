<?php

$hooks->on('view.main_menu', function () {
    echo '<li class="nav-item"><a class="nav-link" href="' . url('quran') . '">Quran</a></li>';
});
