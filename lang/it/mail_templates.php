<?php

// Built-in Italian text of the client emails (App\Enums\MailTemplate), used for clients whose email language is
// Italian until the admin saves an Italian text of their own. Same placeholders as the English text. Payment notices
// are proformas: call them avviso di pagamento, never fattura.

$notice = "{\$invoice_items}\n\n{\$invoice_tax}\n\nTotale: **{\$invoice_total}**, da pagare entro il {\$invoice_due_date}.\n\n{\$payment_instructions}";

return [
    'greeting' => 'Gentile {$client_first_name},',
    'closing' => "Cordiali saluti,<br>\n{\$signature}",

    'client_signup' => [
        'subject' => 'Benvenuto in {$company_name}',
        'body' => 'Grazie per aver scelto {$company_name}. Il Suo account è stato creato con l\'indirizzo email {$client_email}.',
    ],
    'client_area_invite' => [
        'subject' => 'La Sua area clienti di {$company_name}',
        'body' => "Ora può vedere i Suoi servizi, domini e avvisi di pagamento nell'area clienti di {\$company_name}.\n\n"
            ."[Scelga la Sua password]({\$set_password_url})\n\nIl link funziona una sola volta, per 48 ore. Il nome utente è il Suo indirizzo email, {\$client_email}. "
            .'In seguito acceda da {$client_area_url}, dove "Password dimenticata?" Le invia un nuovo link.',
    ],
    'client_area_login' => [
        'subject' => 'I Suoi dati di accesso all\'area clienti di {$company_name}',
        'body' => "Benvenuto in {\$company_name}. Ora può vedere i Suoi servizi, domini e avvisi di pagamento nella nostra area clienti.\n\n"
            ."Indirizzo: {\$client_area_url}\n\nNome utente: {\$client_email}\n\nPassword: {\$password}\n\n"
            .'La preghiamo di cambiare la password dal Suo profilo dopo l\'accesso. Se la dimentica, "Password dimenticata?" nella pagina di accesso Le invia un nuovo link.',
    ],
    'email_verification' => [
        'subject' => 'Confermi il Suo indirizzo email per {$company_name}',
        'body' => "Grazie per essersi registrato su {\$company_name}. Per completare, confermi il Suo indirizzo email:\n\n"
            ."[Conferma il mio indirizzo email]({\$verification_url})\n\n"
            .'Il link funziona per 60 minuti; dopo, acceda e ne richieda uno nuovo. Se non si è registrato Lei, ignori questa email.',
    ],
    'order_confirmation' => [
        'subject' => 'Conferma dell\'ordine #{$order_number}',
        'body' => "Abbiamo ricevuto il Suo ordine #{\$order_number} del {\$order_date}.\n\n{\$order_items}\n\nTotale: **{\$order_total}**\n\n{\$payment_instructions}",
    ],

    'renewal_reminder' => [
        'subject' => 'Prossimo rinnovo dei Suoi servizi',
        'body' => "I seguenti servizi si avvicinano alla data di rinnovo.\n\n{\$services}\n\n{\$payment_instructions}\n\nSe ha già pagato, ignori questo messaggio.",
    ],
    'overdue_notice' => [
        'subject' => 'Pagamento scaduto: servizi in attesa di pagamento',
        'body' => "La scadenza dei seguenti servizi è passata. La preghiamo di effettuare il pagamento per evitarne la sospensione.\n\n{\$services}\n\n{\$payment_instructions}\n\nSe ha già pagato, ignori questo messaggio.",
    ],
    'invoice_created' => [
        'subject' => 'Avviso di pagamento {$invoice_number}',
        'body' => "Le inviamo l'avviso di pagamento {\$invoice_number} del {\$invoice_date} per i Suoi servizi. Si tratta di un avviso di pagamento, non di un documento fiscale.\n\n{$notice}",
    ],
    'invoice_payment_reminder' => [
        'subject' => 'Promemoria: l\'avviso di pagamento {$invoice_number} è da pagare entro il {$invoice_due_date}',
        'body' => "Le ricordiamo che l'avviso di pagamento {\$invoice_number} è da pagare entro il {\$invoice_due_date}.\n\n{$notice}",
    ],
    'invoice_overdue_first' => [
        'subject' => 'L\'avviso di pagamento {$invoice_number} è scaduto',
        'body' => "L'avviso di pagamento {\$invoice_number} era da pagare entro il {\$invoice_due_date} e risulta ancora non pagato. Giorni di ritardo: {\$days_overdue}.\n\n{$notice}",
    ],
    'invoice_overdue_second' => [
        'subject' => 'Secondo promemoria: l\'avviso di pagamento {$invoice_number} è scaduto',
        'body' => "Le ricordiamo per la seconda volta che l'avviso di pagamento {\$invoice_number}, da pagare entro il {\$invoice_due_date}, risulta ancora non pagato. La preghiamo di saldarlo per evitare la sospensione dei Suoi servizi.\n\n{$notice}",
    ],
    'invoice_modified' => [
        'subject' => 'Aggiornamento dell\'avviso di pagamento {$invoice_number}',
        'body' => "L'avviso di pagamento {\$invoice_number} è stato aggiornato.\n\n{$notice}",
    ],
    'tax_invoice_issued' => [
        'subject' => 'Fattura {$tax_invoice_number}',
        'body' => "Grazie per il pagamento. In allegato trova la fattura **{\$tax_invoice_number}** del {\$tax_invoice_date}, relativa all'avviso di pagamento {\$invoice_number}.\n\n{\$invoice_items}\n\n{\$invoice_tax}\n\nTotale pagato: **{\$invoice_total}**",
    ],
    'price_change' => [
        'subject' => 'Cambio di prezzo dei Suoi servizi dal {$effective_date}',
        'body' => "La informiamo che il prezzo dei seguenti servizi cambia per i rinnovi in scadenza dal **{\$effective_date}**.\n\n"
            ."{\$price_changes}\n\nFino ad allora non cambia nulla, e gli avvisi di pagamento già emessi restano invariati.\n\n"
            .'Per qualsiasi domanda, o se desidera parlare del Suo piano, risponda a questa email.',
    ],

    'quote' => [
        'subject' => 'Preventivo {$quote_number} da {$company_name}',
        'body' => "Grazie per il Suo interesse. Ecco il nostro preventivo **{\$quote_number}**.\n\n{\$quote_intro}\n\n{\$quote_items}\n\n"
            ."Primo pagamento: **{\$quote_total}**. Il preventivo è valido fino al {\$quote_valid_until}.\n\n"
            .'[Visualizza e accetta il preventivo]({$quote_url})'."\n\n".'Per qualsiasi domanda, risponda semplicemente a questa email.',
    ],

    'hosting_welcome' => [
        'subject' => 'L\'account hosting per {$domain} è pronto',
        'body' => "Il Suo account hosting per **{\$domain}** è pronto.\n\n"
            ."Pannello di controllo: {\$control_panel_url}<br>\nNome utente: {\$username}<br>\nPassword: {\$password}\n\n"
            ."Punti il Suo dominio ai nostri nameserver, o il suo record A a {\$server_ip}.\n\n"
            .'Pacchetto: {$package}, {$amount} ({$billing_cycle}), prossimo pagamento entro il {$next_due_date}.',
    ],
    'service_suspension' => [
        'subject' => 'Sospensione del servizio: {$service}',
        'body' => "Il Suo servizio **{\$service}** {\$domain} è stato sospeso.\n\nMotivo: {\$suspension_reason}\n\n{\$payment_instructions}",
    ],
    'service_unsuspension' => [
        'subject' => 'Riattivazione del servizio: {$service}',
        'body' => 'Il Suo servizio **{$service}** {$domain} è di nuovo attivo.',
    ],

    'cancellation_approved' => [
        'subject' => 'Conferma della disdetta: {$service}',
        'body' => "Abbiamo ricevuto la richiesta di disdetta del Suo servizio **{\$service}** {\$domain} ({\$cancellation_type}).\n\n"
            .'Il servizio termina il {$cancellation_date} e non riceverà più avvisi di pagamento per esso.',
    ],

    'cancellation_rejected' => [
        'subject' => 'La richiesta di disdetta per {$service}',
        'body' => "Non abbiamo potuto eseguire la richiesta di disdetta del Suo servizio **{\$service}** {\$domain}, quindi il servizio resta invariato.\n\n"
            .'Ci contatti se desidera parlarne.',
    ],

    'domain_registration' => [
        'subject' => 'Registrazione del dominio: {$domain}',
        'body' => "Il dominio **{\$domain}** è stato registrato. Anni di registrazione: {\$registration_years}.\n\nNameserver:<br>\n{\$nameservers}",
    ],
    'domain_renewal' => [
        'subject' => 'Rinnovo del dominio: {$domain}',
        'body' => 'Il dominio **{$domain}** è stato rinnovato. Anni di rinnovo: {$registration_years}. Prossimo pagamento entro il {$next_due_date}.',
    ],
    'domain_transfer_completed' => [
        'subject' => 'Trasferimento del dominio completato: {$domain}',
        'body' => "Il trasferimento di **{\$domain}** da noi è completato. Scade il {\$expiry_date}.\n\nNameserver:<br>\n{\$nameservers}",
    ],
    'domain_renewal_notice' => [
        'subject' => 'Prossimo rinnovo dei Suoi domini',
        'body' => "I seguenti domini si avvicinano alla data di rinnovo.\n\n{\$services}\n\n{\$payment_instructions}\n\nSe ha già pagato, ignori questo messaggio.",
    ],
    'domain_expired' => [
        'subject' => 'Il dominio {$domain} è scaduto',
        'body' => "Il dominio **{\$domain}** è scaduto il {\$expiry_date}. Lo rinnovi al più presto, altrimenti potrebbe essere registrato da chiunque.\n\n{\$payment_instructions}",
    ],

    'usage_warning' => [
        'subject' => '{$domain} si avvicina al limite del suo pacchetto',
        'body' => "Il Suo account hosting **{\$service}** {\$domain} si avvicina ai limiti del suo pacchetto ({\$package}):\n\n{\$usage}\n\n"
            .'Al raggiungimento del limite, il sito web o le email potrebbero smettere di funzionare. Per evitarlo, può liberare spazio o passare a un pacchetto più grande: prema **Upgrade / downgrade** nella pagina del servizio nell\'area clienti, o risponda a questa email e Le suggeriremo quello più adatto.',
    ],
    'upgrade_approved' => [
        'subject' => 'Cambio di pacchetto: {$service}',
        'body' => "Il Suo servizio **{\$service}** {\$domain} è stato spostato a **{\$new_product}** e il nuovo pacchetto è già attivo.\n\n"
            .'Il nuovo prezzo è **{$new_price}** ({$billing_cycle}), addebitato dalla prossima scadenza, il {$effective_date}. Se addebitiamo la parte restante del periodo in corso, riceverà un avviso di pagamento separato.',
    ],
    'upgrade_rejected' => [
        'subject' => 'La richiesta di cambio pacchetto per {$service}',
        'body' => "Non abbiamo potuto spostare il Suo servizio **{\$service}** {\$domain} a **{\$new_product}**, quindi resta invariato.\n\n"
            .'Ci contatti se desidera parlarne.',
    ],
    'hosting_offer' => [
        'subject' => 'Hosting per {$domain}',
        'body' => "Il Suo dominio **{\$domain}** è presso di noi, ma il sito web e le email sono ospitati altrove o non sono ancora stati configurati.\n\n"
            ."Possiamo ospitarli noi, con indirizzi email sul Suo dominio, così ha tutto in un unico posto con un'unica data di rinnovo.\n\n"
            .'Se è interessato, risponda a questa email e Le suggeriremo il piano più adatto.',
    ],
    'domain_transfer_offer' => [
        'subject' => 'Trasferisca {$domain} a {$company_name}',
        'body' => "Abbiamo notato che **{\$domain}** è registrato presso un altro provider. Possiamo gestirlo insieme agli altri Suoi servizi, con un'unica data di rinnovo e un unico avviso di pagamento.\n\n"
            ."Prezzo di trasferimento: {\$transfer_price}\n\n"
            .'Se desidera che lo trasferiamo, risponda a questa email e Le invieremo i passaggi.',
    ],
    'domain_winback' => [
        'subject' => 'Può ancora rinnovare {$domain}',
        'body' => 'Il dominio **{$domain}** è scaduto il {$expiry_date} e non è stato rinnovato. Può ancora rinnovarlo per {$renewal_price}. '
            ."Tra qualche giorno recuperarlo costerà molto di più, e in seguito chiunque potrà registrarlo.\n\n"
            .'Se desidera mantenerlo, risponda a questa email e lo rinnoveremo subito.',
    ],
    'ssl_validation' => [
        'subject' => 'Azione richiesta: convalidi {$domain} per il certificato SSL',
        'body' => "Per emettere il certificato SSL di **{\$domain}**, l'autorità di certificazione ha bisogno della prova che il dominio è Suo. Metodo: {\$validation_method}.\n\n{\$validation}\n\n"
            .'Può vedere queste informazioni, scegliere un altro metodo e scaricare il certificato una volta emesso nella pagina del servizio nell\'area clienti.',
    ],
    'ssl_issued' => [
        'subject' => 'Il certificato SSL di {$domain} è pronto',
        'body' => "Il certificato SSL di **{\$domain}** è stato emesso ed è valido fino al {\$expiry_date}.\n\n"
            .'Lo scarichi dalla pagina del servizio nell\'area clienti (certificato, CA bundle e chiave privata) e lo installi sul Suo sito web. Se il Suo sito è ospitato da noi, risponda a questa email e lo installeremo noi.',
    ],
    'ticket_opened' => [
        'subject' => 'Ticket di supporto [{$ticket_number}] {$ticket_subject}',
        'body' => "Il Suo ticket di supporto **{\$ticket_number}**, «{\$ticket_subject}», è aperto:\n\n{\$ticket_message}\n\n"
            .'Rispondiamo sul ticket e via email, e può seguirlo e rispondere dall\'area clienti.',
    ],
    'ticket_reply' => [
        'subject' => 'Re: [{$ticket_number}] {$ticket_subject}',
        'body' => "C'è una nuova risposta al Suo ticket di supporto **{\$ticket_number}**, «{\$ticket_subject}»:\n\n{\$ticket_message}\n\n"
            .'Può rispondere dall\'area clienti.',
    ],
    'ticket_closed' => [
        'subject' => 'Chiuso: [{$ticket_number}] {$ticket_subject}',
        'body' => "Il Suo ticket di supporto **{\$ticket_number}**, «{\$ticket_subject}», è stato chiuso, poiché non abbiamo Sue notizie da qualche giorno.\n\n"
            .'Se ha ancora bisogno di aiuto, risponda dall\'area clienti e verrà riaperto.',
    ],
];
