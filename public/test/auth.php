<?php

include __DIR__ . '/_start.php';

// $totalcms->restrictPageAccess(collection: 'users');

?>

<h1>Total CMS Auth Test</h1>

{% if cms.userLoggedIn('users') %}
{%set user = cms.userData('users') %}
<p>Hello {{ user.name }}</p>
<a href="{{ cms.logout }}">Logout</a>
{% else %}
<a href="{{ cms.login('users') }}">Please Login</a>
{% endif %}


{% if cms.userHasAccess('admin') %}
<p>Hello Admin</p>
{% else %}
<p>You are not an Admin</p>
{% endif %}



<?php include __DIR__ . '/_end.php'; ?>