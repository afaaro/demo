<?php


$hooks->on('view.vars', function($vars) use ($registry) {
    $route = $registry->get('request')->get('route');
    $vars['body_class'] = $route;
    
    return $vars;
});


$hooks->on('view.main_menu', function() use ($registry) {
    echo '<li class="nav-item"><a class="nav-link" href="'.url('media').'">Media</a></li>';
});