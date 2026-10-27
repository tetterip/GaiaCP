<?php

// Brazilian Portuguese texts of Laravel's validation messages for the rules GaiaCP's forms use; any other rule falls back to English.
// :attribute is the field's label as the form shows it.

return [
    'accepted' => 'Você precisa aceitar o campo ":attribute".',
    'confirmed' => 'A confirmação do campo ":attribute" não confere.',
    'current_password' => 'A senha não está correta.',
    'date' => 'O campo ":attribute" deve ser uma data válida.',
    'different' => 'Os campos ":attribute" e ":other" devem ser diferentes.',
    'email' => 'O campo ":attribute" deve ser um endereço de e-mail válido.',
    'exists' => 'A opção escolhida no campo ":attribute" não é válida.',
    'in' => 'A opção escolhida no campo ":attribute" não é válida.',
    'integer' => 'O campo ":attribute" deve ser um número inteiro.',
    'max' => [
        'numeric' => 'O campo ":attribute" não pode ser maior que :max.',
        'string' => 'O campo ":attribute" não pode ter mais de :max caracteres.',
        'array' => 'O campo ":attribute" não pode ter mais de :max itens.',
        'file' => 'O campo ":attribute" não pode ser maior que :max kilobytes.',
    ],
    'min' => [
        'numeric' => 'O campo ":attribute" deve ser pelo menos :min.',
        'string' => 'O campo ":attribute" deve ter pelo menos :min caracteres.',
        'array' => 'O campo ":attribute" deve ter pelo menos :min itens.',
        'file' => 'O campo ":attribute" deve ter pelo menos :min kilobytes.',
    ],
    'numeric' => 'O campo ":attribute" deve ser um número.',
    'password' => [
        'letters' => 'O campo ":attribute" deve conter pelo menos uma letra.',
        'mixed' => 'O campo ":attribute" deve conter pelo menos uma letra maiúscula e uma minúscula.',
        'numbers' => 'O campo ":attribute" deve conter pelo menos um número.',
        'symbols' => 'O campo ":attribute" deve conter pelo menos um símbolo.',
        'uncompromised' => 'Esta senha apareceu em um vazamento de dados. Escolha outra.',
    ],
    'regex' => 'O formato do campo ":attribute" não é válido.',
    'required' => 'O campo ":attribute" é obrigatório.',
    'required_if' => 'O campo ":attribute" é obrigatório quando ":other" é :value.',
    'same' => 'Os campos ":attribute" e ":other" devem ser iguais.',
    'string' => 'O campo ":attribute" deve ser um texto.',
    'unique' => 'Este valor do campo ":attribute" já está em uso.',
    'url' => 'O campo ":attribute" deve ser um endereço URL válido.',
];
