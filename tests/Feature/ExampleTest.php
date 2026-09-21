<?php

// The root path has always redirected to the dashboard; this starter-kit test
// asserted 200 and had been failing since the route was first added.
it('redirects the root path to the dashboard', function () {
    $this->get('/')->assertRedirect('/dashboard');
});
