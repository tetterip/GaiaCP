<?php

// Built-in Brazilian Portuguese text of the client emails (App\Enums\MailTemplate), used for clients whose email
// language is Brazilian Portuguese until the admin saves a text of their own. Same placeholders as the English text.
// Payment notices are proformas: call them aviso de pagamento, never fatura or nota fiscal.

$notice = "{\$invoice_items}\n\n{\$invoice_tax}\n\nTotal: **{\$invoice_total}**, a pagar até {\$invoice_due_date}.\n\n{\$payment_instructions}";

return [
    'greeting' => 'Olá, {$client_first_name},',
    'closing' => "Atenciosamente,<br>\n{\$signature}",

    'client_signup' => [
        'subject' => 'Boas-vindas à {$company_name}',
        'body' => 'Obrigado por escolher a {$company_name}. Sua conta foi criada com o endereço de e-mail {$client_email}.',
    ],
    'client_area_invite' => [
        'subject' => 'Sua área do cliente na {$company_name}',
        'body' => "Agora você pode ver seus serviços, domínios e avisos de pagamento na área do cliente da {\$company_name}.\n\n"
            ."[Defina sua senha]({\$set_password_url})\n\nO link funciona uma vez, por 48 horas. Seu nome de usuário é o seu endereço de e-mail, {\$client_email}. "
            .'Depois, entre em {$client_area_url}, onde "Esqueceu a senha?" envia um novo link para você.',
    ],
    'client_area_login' => [
        'subject' => 'Seus dados de acesso à área do cliente da {$company_name}',
        'body' => "Boas-vindas à {\$company_name}. Agora você pode ver seus serviços, domínios e avisos de pagamento na nossa área do cliente.\n\n"
            ."Endereço: {\$client_area_url}\n\nNome de usuário: {\$client_email}\n\nSenha: {\$password}\n\n"
            .'Altere a senha no seu perfil assim que entrar. Se você a esquecer, "Esqueceu a senha?" na página de login envia um novo link para você.',
    ],
    'email_verification' => [
        'subject' => 'Confirme seu endereço de e-mail na {$company_name}',
        'body' => "Obrigado por se cadastrar na {\$company_name}. Para concluir, confirme seu endereço de e-mail:\n\n"
            ."[Confirmar o endereço de e-mail]({\$verification_url})\n\n"
            .'O link funciona por 60 minutos; depois disso, entre e peça um novo. Se não foi você quem se cadastrou, ignore este e-mail.',
    ],
    'order_confirmation' => [
        'subject' => 'Confirmação do pedido #{$order_number}',
        'body' => "Recebemos seu pedido #{\$order_number} de {\$order_date}.\n\n{\$order_items}\n\nTotal: **{\$order_total}**\n\n{\$payment_instructions}",
    ],

    'renewal_reminder' => [
        'subject' => 'Renovação próxima dos seus serviços',
        'body' => "Os serviços abaixo estão chegando à data de renovação.\n\n{\$services}\n\n{\$payment_instructions}\n\nSe você já pagou, desconsidere esta mensagem.",
    ],
    'overdue_notice' => [
        'subject' => 'Pagamento em atraso: serviços aguardando pagamento',
        'body' => "O vencimento dos serviços abaixo já passou. Regularize o pagamento para evitar a suspensão deles.\n\n{\$services}\n\n{\$payment_instructions}\n\nSe você já pagou, desconsidere esta mensagem.",
    ],
    'invoice_created' => [
        'subject' => 'Aviso de pagamento {$invoice_number}',
        'body' => "Enviamos o aviso de pagamento {\$invoice_number} de {\$invoice_date} referente aos seus serviços. Trata-se de um aviso de pagamento, não de um documento fiscal.\n\n{$notice}",
    ],
    'invoice_payment_reminder' => [
        'subject' => 'Lembrete: o aviso de pagamento {$invoice_number} vence em {$invoice_due_date}',
        'body' => "Lembramos que o aviso de pagamento {\$invoice_number} deve ser pago até {\$invoice_due_date}.\n\n{$notice}",
    ],
    'invoice_overdue_first' => [
        'subject' => 'O aviso de pagamento {$invoice_number} está em atraso',
        'body' => "O aviso de pagamento {\$invoice_number} venceu em {\$invoice_due_date} e continua em aberto. Dias de atraso: {\$days_overdue}.\n\n{$notice}",
    ],
    'invoice_overdue_second' => [
        'subject' => 'Segundo lembrete: o aviso de pagamento {$invoice_number} está em atraso',
        'body' => "Lembramos pela segunda vez que o aviso de pagamento {\$invoice_number}, com vencimento em {\$invoice_due_date}, continua em aberto. Faça o pagamento para evitar a suspensão dos seus serviços.\n\n{$notice}",
    ],
    'invoice_modified' => [
        'subject' => 'Atualização do aviso de pagamento {$invoice_number}',
        'body' => "O aviso de pagamento {\$invoice_number} foi atualizado.\n\n{$notice}",
    ],
    'price_change' => [
        'subject' => 'Mudança de preço dos seus serviços a partir de {$effective_date}',
        'body' => "Informamos que o preço dos serviços abaixo muda para as renovações com vencimento a partir de **{\$effective_date}**.\n\n"
            ."{\$price_changes}\n\nAté lá nada muda, e os avisos de pagamento já emitidos continuam como estão.\n\n"
            .'Se tiver qualquer dúvida, ou se quiser conversar sobre o seu pacote, responda a este e-mail.',
    ],

    'quote' => [
        'subject' => 'Orçamento {$quote_number} da {$company_name}',
        'body' => "Obrigado pelo seu interesse. Segue o nosso orçamento **{\$quote_number}**.\n\n{\$quote_intro}\n\n{\$quote_items}\n\n"
            ."Primeiro pagamento: **{\$quote_total}**. O orçamento é válido até {\$quote_valid_until}.\n\n"
            .'[Veja e aceite o orçamento]({$quote_url})'."\n\n".'Se tiver qualquer dúvida, responda a este e-mail.',
    ],

    'hosting_welcome' => [
        'subject' => 'A conta de hospedagem de {$domain} está pronta',
        'body' => "Sua conta de hospedagem para **{\$domain}** está pronta.\n\n"
            ."Painel de controle: {\$control_panel_url}<br>\nNome de usuário: {\$username}<br>\nSenha: {\$password}\n\n"
            ."Aponte seu domínio para os nossos nameservers ou o registro A dele para o endereço {\$server_ip}.\n\n"
            .'Pacote: {$package}, {$amount} ({$billing_cycle}), próximo pagamento até {$next_due_date}.',
    ],
    'service_suspension' => [
        'subject' => 'Serviço suspenso: {$service}',
        'body' => "Seu serviço **{\$service}** {\$domain} foi suspenso.\n\nMotivo: {\$suspension_reason}\n\n{\$payment_instructions}",
    ],
    'service_unsuspension' => [
        'subject' => 'Serviço reativado: {$service}',
        'body' => 'Seu serviço **{$service}** {$domain} está ativo novamente.',
    ],

    'cancellation_approved' => [
        'subject' => 'Confirmação de cancelamento: {$service}',
        'body' => "Recebemos a solicitação de cancelamento do seu serviço **{\$service}** {\$domain} ({\$cancellation_type}).\n\n"
            .'O serviço termina em {$cancellation_date} e você não receberá mais avisos de pagamento referentes a ele.',
    ],

    'cancellation_rejected' => [
        'subject' => 'A solicitação de cancelamento de {$service}',
        'body' => "Não pudemos realizar a solicitação de cancelamento do seu serviço **{\$service}** {\$domain}, então o serviço continua como está.\n\n"
            .'Fale conosco se quiser conversar sobre isso.',
    ],

    'domain_registration' => [
        'subject' => 'Registro de domínio: {$domain}',
        'body' => "O domínio **{\$domain}** foi registrado. Anos de registro: {\$registration_years}.\n\nNameservers:<br>\n{\$nameservers}",
    ],
    'domain_renewal' => [
        'subject' => 'Renovação de domínio: {$domain}',
        'body' => 'O domínio **{$domain}** foi renovado. Anos de renovação: {$registration_years}. Próximo pagamento até {$next_due_date}.',
    ],
    'domain_transfer_completed' => [
        'subject' => 'Transferência de domínio concluída: {$domain}',
        'body' => "A transferência de **{\$domain}** para nós foi concluída. Ele expira em {\$expiry_date}.\n\nNameservers:<br>\n{\$nameservers}",
    ],
    'domain_renewal_notice' => [
        'subject' => 'Renovação próxima dos seus domínios',
        'body' => "Os domínios abaixo estão chegando à data de renovação.\n\n{\$services}\n\n{\$payment_instructions}\n\nSe você já pagou, desconsidere esta mensagem.",
    ],
    'domain_expired' => [
        'subject' => 'O domínio {$domain} expirou',
        'body' => "O domínio **{\$domain}** expirou em {\$expiry_date}. Renove-o o quanto antes; caso contrário, qualquer pessoa poderá registrá-lo.\n\n{\$payment_instructions}",
    ],

    'usage_warning' => [
        'subject' => '{$domain} está perto do limite do pacote',
        'body' => "Sua conta de hospedagem **{\$service}** {\$domain} está perto dos limites do pacote ({\$package}):\n\n{\$usage}\n\n"
            .'Quando chegar ao limite, o site ou os e-mails podem parar de funcionar. Para evitar isso, você pode liberar espaço ou mudar para um pacote maior: clique em **Upgrade / downgrade** na página do serviço na área do cliente ou responda a este e-mail e sugeriremos o que for melhor para você.',
    ],
    'upgrade_approved' => [
        'subject' => 'Mudança de pacote: {$service}',
        'body' => "Seu serviço **{\$service}** {\$domain} foi movido para **{\$new_product}** e o novo pacote já está valendo.\n\n"
            .'O novo preço é **{$new_price}** ({$billing_cycle}) e será cobrado a partir do próximo vencimento, {$effective_date}. Se cobrarmos a diferença do período atual, você receberá um aviso de pagamento separado.',
    ],
    'upgrade_rejected' => [
        'subject' => 'A solicitação de mudança de pacote de {$service}',
        'body' => "Não pudemos mover seu serviço **{\$service}** {\$domain} para **{\$new_product}**, então ele continua como está.\n\n"
            .'Fale conosco se quiser conversar sobre isso.',
    ],
    'hosting_offer' => [
        'subject' => 'Hospedagem para {$domain}',
        'body' => "Seu domínio **{\$domain}** está conosco, mas o site e os e-mails dele estão hospedados em outro lugar ou ainda não foram configurados.\n\n"
            ."Podemos hospedá-los, com endereços de e-mail no seu domínio, para que você tenha tudo em um só lugar, com uma única data de renovação.\n\n"
            .'Se tiver interesse, responda a este e-mail e sugeriremos o pacote ideal para você.',
    ],
    'domain_transfer_offer' => [
        'subject' => 'Transfira {$domain} para a {$company_name}',
        'body' => "Vimos que **{\$domain}** está registrado em outro provedor. Podemos cuidar dele junto com seus outros serviços, com uma única data de renovação e um único aviso de pagamento.\n\n"
            ."Preço da transferência: {\$transfer_price}\n\n"
            .'Se quiser que o transfiramos, responda a este e-mail e enviaremos os passos.',
    ],
    'domain_winback' => [
        'subject' => 'Você ainda pode renovar {$domain}',
        'body' => 'O domínio **{$domain}** expirou em {$expiry_date} e não foi renovado. Você ainda pode renová-lo por {$renewal_price}. '
            ."Em poucos dias, recuperá-lo custará muito mais e, depois, qualquer pessoa poderá registrá-lo.\n\n"
            .'Se quiser mantê-lo, responda a este e-mail e o renovaremos imediatamente.',
    ],
    'ssl_validation' => [
        'subject' => 'Ação necessária: valide {$domain} para o certificado SSL',
        'body' => "Para emitir o certificado SSL de **{\$domain}**, a autoridade certificadora precisa de uma prova de que o domínio é seu. Método: {\$validation_method}.\n\n{\$validation}\n\n"
            .'Na página do serviço na área do cliente, você vê essas informações, pode escolher outro método e baixar o certificado assim que ele for emitido.',
    ],
    'ssl_issued' => [
        'subject' => 'O certificado SSL de {$domain} está pronto',
        'body' => "O certificado SSL de **{\$domain}** foi emitido e é válido até {\$expiry_date}.\n\n"
            .'Baixe-o na página do serviço na área do cliente (certificado, CA bundle e chave privada) e instale-o no seu site. Se o seu site está hospedado conosco, responda a este e-mail e nós o instalaremos.',
    ],
    'ticket_opened' => [
        'subject' => 'Ticket de suporte [{$ticket_number}] {$ticket_subject}',
        'body' => "Seu ticket de suporte **{\$ticket_number}**, \"{\$ticket_subject}\", está aberto:\n\n{\$ticket_message}\n\n"
            .'Respondemos ao ticket também por e-mail, e você pode acompanhá-lo e responder pela área do cliente.',
    ],
    'ticket_reply' => [
        'subject' => 'Resposta: [{$ticket_number}] {$ticket_subject}',
        'body' => "Há uma nova resposta no seu ticket de suporte **{\$ticket_number}**, \"{\$ticket_subject}\":\n\n{\$ticket_message}\n\n"
            .'Você pode responder pela área do cliente.',
    ],
    'ticket_closed' => [
        'subject' => 'Fechado: [{$ticket_number}] {$ticket_subject}',
        'body' => "Seu ticket de suporte **{\$ticket_number}**, \"{\$ticket_subject}\", foi fechado, pois não tivemos notícias suas há alguns dias.\n\n"
            .'Se ainda precisar de ajuda, responda a ele pela área do cliente e ele será reaberto.',
    ],
];
