<?php
/**
 * Aliases allows us to point a specific URI to whatever we want inside
 * the application/modules/ directory.. neat huh
 * @TODO add regex possibilities
 */
$WS->addAlias('vc/teacher', [
    'scope' => 'vc',
    'target' => 'teacher'
]);
