<?php

// Hungarian messages for the validation rules this app uses. Add a line here when a new rule is introduced;
// anything missing falls back to the framework's English message.

return [

    'boolean' => 'A(z) :attribute mező értéke csak igaz vagy hamis lehet.',
    'in' => 'A kiválasztott :attribute érvénytelen.',
    'integer' => 'A(z) :attribute mező értékének egész számnak kell lennie.',
    'max' => [
        'array' => 'A(z) :attribute legfeljebb :max elemet tartalmazhat.',
        'file' => 'A(z) :attribute legfeljebb :max kilobájt lehet.',
        'numeric' => 'A(z) :attribute legfeljebb :max lehet.',
        'string' => 'A(z) :attribute legfeljebb :max karakter lehet.',
    ],
    'array' => 'A(z) :attribute mező értékének listának kell lennie.',
    'min' => [
        'array' => 'A(z) :attribute legalább :min elemet kell tartalmazzon.',
        'file' => 'A(z) :attribute legalább :min kilobájt kell legyen.',
        'numeric' => 'A(z) :attribute legalább :min kell legyen.',
        'string' => 'A(z) :attribute legalább :min karakter kell legyen.',
    ],
    'size' => [
        'array' => 'A(z) :attribute pontosan :size elemet kell tartalmazzon.',
        'string' => 'A(z) :attribute pontosan :size karakter kell legyen.',
        'numeric' => 'A(z) :attribute értéke :size kell legyen.',
        'file' => 'A(z) :attribute mérete :size kilobájt kell legyen.',
    ],
    'required' => 'A(z) :attribute megadása kötelező.',
    'string' => 'A(z) :attribute mező értékének szövegnek kell lennie.',

    'attributes' => [
        'name' => 'név',
        'code' => 'szobakód',
        'capacity' => 'asztal mérete',
        'seat' => 'hely',
        'ready' => 'készenlét',
        'locale' => 'nyelv',
        'index' => 'vezeték',
        'target' => 'célpont',
        'value' => 'érték',
        'mission' => 'küldetés',
        'indices' => 'vezetékek',
    ],

];
