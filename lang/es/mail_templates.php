<?php

// Built-in Spanish text of the client emails (App\Enums\MailTemplate), used for clients whose email language is
// Spanish until the admin saves a Spanish text of their own. Same placeholders as the English text. Payment notices
// are proformas: call them aviso de pago, never factura.

$notice = "{\$invoice_items}\n\n{\$invoice_tax}\n\nTotal: **{\$invoice_total}**, a pagar antes del {\$invoice_due_date}.\n\n{\$payment_instructions}";

return [
    'greeting' => 'Estimado/a {$client_first_name}:',
    'closing' => "Atentamente,<br>\n{\$signature}",

    'client_signup' => [
        'subject' => 'Bienvenido/a a {$company_name}',
        'body' => 'Gracias por elegir {$company_name}. Su cuenta se ha creado con la dirección de email {$client_email}.',
    ],
    'client_area_invite' => [
        'subject' => 'Su área de clientes en {$company_name}',
        'body' => "Ya puede ver sus servicios, dominios y avisos de pago en el área de clientes de {\$company_name}.\n\n"
            ."[Elija su contraseña]({\$set_password_url})\n\nEl enlace funciona una vez, durante 48 horas. Su nombre de usuario es su dirección de email, {\$client_email}. "
            .'Más adelante, inicie sesión en {$client_area_url}, donde «¿Ha olvidado su contraseña?» le envía un enlace nuevo.',
    ],
    'client_area_login' => [
        'subject' => 'Sus datos de acceso al área de clientes de {$company_name}',
        'body' => "Bienvenido/a a {\$company_name}. Ya puede ver sus servicios, dominios y avisos de pago en nuestra área de clientes.\n\n"
            ."Dirección: {\$client_area_url}\n\nNombre de usuario: {\$client_email}\n\nContraseña: {\$password}\n\n"
            .'Cambie la contraseña desde su perfil después de iniciar sesión. Si la olvida, «¿Ha olvidado su contraseña?» en la página de inicio de sesión le envía un enlace nuevo.',
    ],
    'email_verification' => [
        'subject' => 'Confirme su dirección de email en {$company_name}',
        'body' => "Gracias por registrarse en {\$company_name}. Para terminar, confirme su dirección de email:\n\n"
            ."[Confirmar mi dirección de email]({\$verification_url})\n\n"
            .'El enlace funciona durante 60 minutos; después, inicie sesión y pida uno nuevo. Si no se registró usted, ignore este email.',
    ],
    'order_confirmation' => [
        'subject' => 'Confirmación del pedido #{$order_number}',
        'body' => "Hemos recibido su pedido #{\$order_number} del {\$order_date}.\n\n{\$order_items}\n\nTotal: **{\$order_total}**\n\n{\$payment_instructions}",
    ],

    'renewal_reminder' => [
        'subject' => 'Próxima renovación de sus servicios',
        'body' => "Los siguientes servicios se acercan a su fecha de renovación.\n\n{\$services}\n\n{\$payment_instructions}\n\nSi ya ha pagado, ignore este mensaje.",
    ],
    'overdue_notice' => [
        'subject' => 'Pago vencido: servicios pendientes de pago',
        'body' => "Los siguientes servicios han superado su fecha de vencimiento. Realice el pago para evitar su suspensión.\n\n{\$services}\n\n{\$payment_instructions}\n\nSi ya ha pagado, ignore este mensaje.",
    ],
    'invoice_created' => [
        'subject' => 'Aviso de pago {$invoice_number}',
        'body' => "Le enviamos el aviso de pago {\$invoice_number} del {\$invoice_date} por sus servicios. Se trata de un aviso de pago, no de una factura fiscal.\n\n{$notice}",
    ],
    'invoice_payment_reminder' => [
        'subject' => 'Recordatorio: el aviso de pago {$invoice_number} vence el {$invoice_due_date}',
        'body' => "Le recordamos que el aviso de pago {\$invoice_number} vence el {\$invoice_due_date}.\n\n{$notice}",
    ],
    'invoice_overdue_first' => [
        'subject' => 'El aviso de pago {$invoice_number} está vencido',
        'body' => "El aviso de pago {\$invoice_number} vencía el {\$invoice_due_date} y sigue sin pagar. Días de retraso: {\$days_overdue}.\n\n{$notice}",
    ],
    'invoice_overdue_second' => [
        'subject' => 'Segundo recordatorio: el aviso de pago {$invoice_number} está vencido',
        'body' => "Le recordamos por segunda vez que el aviso de pago {\$invoice_number}, con vencimiento el {\$invoice_due_date}, sigue sin pagar. Páguelo para evitar la suspensión de sus servicios.\n\n{$notice}",
    ],
    'invoice_modified' => [
        'subject' => 'Actualización del aviso de pago {$invoice_number}',
        'body' => "El aviso de pago {\$invoice_number} se ha actualizado.\n\n{$notice}",
    ],
    'tax_invoice_issued' => [
        'subject' => 'Factura {$tax_invoice_number}',
        'body' => "Gracias por su pago. Le adjuntamos la factura **{\$tax_invoice_number}** del {\$tax_invoice_date}, correspondiente al aviso de pago {\$invoice_number}.\n\n{\$invoice_items}\n\n{\$invoice_tax}\n\nTotal pagado: **{\$invoice_total}**",
    ],
    'price_change' => [
        'subject' => 'Cambio de precio de sus servicios desde el {$effective_date}',
        'body' => "Le informamos de que el precio de los siguientes servicios cambia para las renovaciones con vencimiento a partir del **{\$effective_date}**.\n\n"
            ."{\$price_changes}\n\nHasta entonces no cambia nada, y los avisos de pago ya emitidos se quedan como están.\n\n"
            .'Si tiene alguna pregunta, o quiere hablar de su plan, responda a este email.',
    ],

    'quote' => [
        'subject' => 'Presupuesto {$quote_number} de {$company_name}',
        'body' => "Gracias por su interés. Le enviamos nuestro presupuesto **{\$quote_number}**.\n\n{\$quote_intro}\n\n{\$quote_items}\n\n"
            ."Primer pago: **{\$quote_total}**. El presupuesto es válido hasta el {\$quote_valid_until}.\n\n"
            .'[Ver y aceptar el presupuesto]({$quote_url})'."\n\n".'Si tiene alguna pregunta, simplemente responda a este email.',
    ],

    'hosting_welcome' => [
        'subject' => 'Su cuenta de hosting para {$domain} está lista',
        'body' => "Su cuenta de hosting para **{\$domain}** está lista.\n\n"
            ."Panel de control: {\$control_panel_url}<br>\nNombre de usuario: {\$username}<br>\nContraseña: {\$password}\n\n"
            ."Apunte su dominio a nuestros servidores de nombres, o su registro A a {\$server_ip}.\n\n"
            .'Paquete: {$package}, {$amount} ({$billing_cycle}), próximo vencimiento el {$next_due_date}.',
    ],
    'service_suspension' => [
        'subject' => 'Servicio suspendido: {$service}',
        'body' => "Su servicio **{\$service}** {\$domain} ha sido suspendido.\n\nMotivo: {\$suspension_reason}\n\n{\$payment_instructions}",
    ],
    'service_unsuspension' => [
        'subject' => 'Servicio reactivado: {$service}',
        'body' => 'Su servicio **{$service}** {$domain} vuelve a estar activo.',
    ],

    'cancellation_approved' => [
        'subject' => 'Confirmación de cancelación: {$service}',
        'body' => "Hemos recibido la solicitud de cancelación de su servicio **{\$service}** {\$domain} ({\$cancellation_type}).\n\n"
            .'El servicio termina el {$cancellation_date} y no recibirá más avisos de pago por él.',
    ],

    'cancellation_rejected' => [
        'subject' => 'Su solicitud de cancelación de {$service}',
        'body' => "No hemos podido llevar a cabo la solicitud de cancelación de su servicio **{\$service}** {\$domain}, así que el servicio se queda como está.\n\n"
            .'Contáctenos si quiere hablarlo.',
    ],

    'domain_registration' => [
        'subject' => 'Registro de dominio: {$domain}',
        'body' => "El dominio **{\$domain}** se ha registrado. Años de registro: {\$registration_years}.\n\nServidores de nombres:<br>\n{\$nameservers}",
    ],
    'domain_renewal' => [
        'subject' => 'Renovación de dominio: {$domain}',
        'body' => 'El dominio **{$domain}** se ha renovado. Años de renovación: {$registration_years}. Próximo vencimiento el {$next_due_date}.',
    ],
    'domain_transfer_completed' => [
        'subject' => 'Transferencia de dominio completada: {$domain}',
        'body' => "La transferencia de **{\$domain}** a nosotros se ha completado. Vence el {\$expiry_date}.\n\nServidores de nombres:<br>\n{\$nameservers}",
    ],
    'domain_renewal_notice' => [
        'subject' => 'Próxima renovación de sus dominios',
        'body' => "Los siguientes dominios se acercan a su fecha de renovación.\n\n{\$services}\n\n{\$payment_instructions}\n\nSi ya ha pagado, ignore este mensaje.",
    ],
    'domain_expired' => [
        'subject' => 'El dominio {$domain} ha vencido',
        'body' => "El dominio **{\$domain}** venció el {\$expiry_date}. Renuévelo lo antes posible; de lo contrario, cualquiera podría registrarlo.\n\n{\$payment_instructions}",
    ],

    'usage_warning' => [
        'subject' => '{$domain} se acerca al límite de su paquete',
        'body' => "Su cuenta de hosting **{\$service}** {\$domain} se acerca a los límites de su paquete ({\$package}):\n\n{\$usage}\n\n"
            .'Cuando llegue al límite, el sitio web o el email pueden dejar de funcionar. Para evitarlo, puede liberar espacio o pasar a un paquete mayor: pulse **Mejorar / reducir** en la página del servicio en el área de clientes, o responda a este email y le sugeriremos el que mejor le convenga.',
    ],
    'upgrade_approved' => [
        'subject' => 'Cambio de paquete: {$service}',
        'body' => "Su servicio **{\$service}** {\$domain} se ha movido a **{\$new_product}** y el paquete nuevo ya está en vigor.\n\n"
            .'Su nuevo precio es **{$new_price}** ({$billing_cycle}) y se cobra desde su próximo vencimiento, el {$effective_date}. Si cobramos el resto del periodo actual, recibirá un aviso de pago aparte.',
    ],
    'upgrade_rejected' => [
        'subject' => 'Su solicitud de cambio de paquete de {$service}',
        'body' => "No hemos podido mover su servicio **{\$service}** {\$domain} a **{\$new_product}**, así que se queda como está.\n\n"
            .'Contáctenos si quiere hablarlo.',
    ],
    'hosting_offer' => [
        'subject' => 'Hosting para {$domain}',
        'body' => "Su dominio **{\$domain}** está con nosotros, pero su sitio web y su email están alojados en otro sitio o aún no se han configurado.\n\n"
            ."Podemos alojarlos nosotros, con direcciones de email en su dominio, y ocuparnos de todo en un solo lugar con una sola fecha de renovación.\n\n"
            .'Si le interesa, responda a este email y le sugeriremos el plan que mejor le convenga.',
    ],
    'domain_transfer_offer' => [
        'subject' => 'Transfiera {$domain} a {$company_name}',
        'body' => "Hemos visto que **{\$domain}** está registrado con otro proveedor. Podemos hacernos cargo de él junto con sus demás servicios, con una sola fecha de renovación y un solo aviso de pago.\n\n"
            ."Precio de transferencia: {\$transfer_price}\n\n"
            .'Si quiere que lo transfiramos, responda a este email y le enviaremos los pasos.',
    ],
    'domain_winback' => [
        'subject' => 'Todavía puede renovar {$domain}',
        'body' => 'El dominio **{$domain}** venció el {$expiry_date} y no se ha renovado. Todavía puede renovarlo por {$renewal_price}. '
            ."Dentro de unos días recuperarlo costará mucho más, y más adelante cualquiera podrá registrarlo.\n\n"
            .'Si quiere conservarlo, responda a este email y lo renovaremos enseguida.',
    ],
    'ssl_validation' => [
        'subject' => 'Acción necesaria: valide {$domain} para su certificado SSL',
        'body' => "Para emitir el certificado SSL de **{\$domain}**, la autoridad de certificación necesita una prueba de que el dominio es suyo. Método: {\$validation_method}.\n\n{\$validation}\n\n"
            .'Puede ver esto, elegir otro método y descargar el certificado cuando se emita en la página del servicio en el área de clientes.',
    ],
    'ssl_issued' => [
        'subject' => 'El certificado SSL de {$domain} está listo',
        'body' => "El certificado SSL de **{\$domain}** se ha emitido y es válido hasta el {\$expiry_date}.\n\n"
            .'Descárguelo desde la página del servicio en el área de clientes (certificado, CA bundle y clave privada) e instálelo en su sitio web. Si su sitio web está alojado con nosotros, responda a este email y lo instalaremos nosotros.',
    ],
    'ticket_opened' => [
        'subject' => 'Ticket de soporte [{$ticket_number}] {$ticket_subject}',
        'body' => "Su ticket de soporte **{\$ticket_number}**, «{\$ticket_subject}», está abierto:\n\n{\$ticket_message}\n\n"
            .'Respondemos en el ticket y por email, y puede seguirlo y responder desde el área de clientes.',
    ],
    'ticket_reply' => [
        'subject' => 'Respuesta: [{$ticket_number}] {$ticket_subject}',
        'body' => "Hay una nueva respuesta en su ticket de soporte **{\$ticket_number}**, «{\$ticket_subject}»:\n\n{\$ticket_message}\n\n"
            .'Puede responder desde el área de clientes.',
    ],
    'ticket_closed' => [
        'subject' => 'Cerrado: [{$ticket_number}] {$ticket_subject}',
        'body' => "Su ticket de soporte **{\$ticket_number}**, «{\$ticket_subject}», se ha cerrado, ya que no hemos tenido noticias suyas en unos días.\n\n"
            .'Si aún necesita ayuda, respóndalo desde el área de clientes y se volverá a abrir.',
    ],
];
