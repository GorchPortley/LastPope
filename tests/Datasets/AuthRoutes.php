<?php

dataset('authroutes', function () {
    return [
        '/',
        '/blog',
        '/designs',
        '/drivers',
        '/dashboard',
        '/dashboard/designs',
        '/dashboard/drivers',
        '/dashboard/messages',
        '/notifications',
        '/settings/profile',
        '/settings/security',
    ];
});

dataset('adminroutes', function () {
    return [
        '/admin',
        '/admin/users',
        '/admin/roles',
        '/admin/permissions',
        '/admin/plans',
        '/admin/posts',
        '/admin/media',
        '/admin/pages',
        '/admin/categories',
        '/admin/changelogs',
        '/admin/themes',
        '/admin/plugins',
        '/admin/settings',
        '/admin/banners',
        '/admin/designs',
        '/admin/drivers',
        '/admin/forms',
    ];
});
