<?php

// Turkish texts of Laravel's validation messages for the rules GaiaCP's forms use; any other rule falls back to English.
// :attribute is the field's label as the form shows it.

return [
    'accepted' => '":attribute" alanını kabul etmelisiniz.',
    'confirmed' => '":attribute" alanının onayı eşleşmiyor.',
    'current_password' => 'Parola doğru değil.',
    'date' => '":attribute" alanı geçerli bir tarih olmalıdır.',
    'different' => '":attribute" ve ":other" alanları farklı olmalıdır.',
    'email' => '":attribute" alanı geçerli bir e-posta adresi olmalıdır.',
    'exists' => '":attribute" alanındaki seçim geçerli değil.',
    'in' => '":attribute" alanındaki seçim geçerli değil.',
    'integer' => '":attribute" alanı bir tam sayı olmalıdır.',
    'max' => [
        'numeric' => '":attribute" alanı :max değerinden büyük olamaz.',
        'string' => '":attribute" alanı :max karakterden uzun olamaz.',
        'array' => '":attribute" alanı :max öğeden fazla içeremez.',
        'file' => '":attribute" alanı :max kilobayttan büyük olamaz.',
    ],
    'min' => [
        'numeric' => '":attribute" alanı en az :min olmalıdır.',
        'string' => '":attribute" alanı en az :min karakter olmalıdır.',
        'array' => '":attribute" alanı en az :min öğe içermelidir.',
        'file' => '":attribute" alanı en az :min kilobayt olmalıdır.',
    ],
    'numeric' => '":attribute" alanı bir sayı olmalıdır.',
    'password' => [
        'letters' => '":attribute" alanı en az bir harf içermelidir.',
        'mixed' => '":attribute" alanı en az bir büyük ve bir küçük harf içermelidir.',
        'numbers' => '":attribute" alanı en az bir rakam içermelidir.',
        'symbols' => '":attribute" alanı en az bir sembol içermelidir.',
        'uncompromised' => 'Bu parola bir veri sızıntısında görülmüş. Başka bir parola seçin.',
    ],
    'regex' => '":attribute" alanının biçimi geçerli değil.',
    'required' => '":attribute" alanı zorunludur.',
    'required_if' => '":other" :value olduğunda ":attribute" alanı zorunludur.',
    'same' => '":attribute" ve ":other" alanları eşleşmelidir.',
    'string' => '":attribute" alanı metin olmalıdır.',
    'unique' => '":attribute" alanındaki bu değer zaten kullanılıyor.',
    'url' => '":attribute" alanı geçerli bir URL olmalıdır.',
];
