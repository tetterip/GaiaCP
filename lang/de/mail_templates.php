<?php

// Built-in German text of the client emails (App\Enums\MailTemplate), used for clients whose email language is
// German until the admin saves a German text of their own. Same placeholders as the English text. Payment notices
// are proformas: call them Zahlungsaufforderung, never Rechnung.

$notice = "{\$invoice_items}\n\n{\$invoice_tax}\n\nGesamt: **{\$invoice_total}**, zahlbar bis {\$invoice_due_date}.\n\n{\$payment_instructions}";

return [
    'greeting' => 'Guten Tag {$client_first_name},',
    'closing' => "Mit freundlichen Grüßen<br>\n{\$signature}",

    'client_signup' => [
        'subject' => 'Willkommen bei {$company_name}',
        'body' => 'Vielen Dank, dass Sie sich für {$company_name} entschieden haben. Ihr Konto wurde mit der E-Mail-Adresse {$client_email} erstellt.',
    ],
    'client_area_invite' => [
        'subject' => 'Ihr Kundenbereich bei {$company_name}',
        'body' => "Sie können Ihre Dienste, Domains und Zahlungsaufforderungen jetzt im Kundenbereich von {\$company_name} einsehen.\n\n"
            ."[Passwort festlegen]({\$set_password_url})\n\nDer Link funktioniert einmal, 48 Stunden lang. Ihr Benutzername ist Ihre E-Mail-Adresse, {\$client_email}. "
            .'Danach melden Sie sich unter {$client_area_url} an, wo Ihnen „Passwort vergessen?“ einen neuen Link sendet.',
    ],
    'client_area_login' => [
        'subject' => 'Ihre Zugangsdaten für den Kundenbereich von {$company_name}',
        'body' => "Willkommen bei {\$company_name}. Sie können Ihre Dienste, Domains und Zahlungsaufforderungen jetzt in unserem Kundenbereich einsehen.\n\n"
            ."Adresse: {\$client_area_url}\n\nBenutzername: {\$client_email}\n\nPasswort: {\$password}\n\n"
            .'Bitte ändern Sie das Passwort nach der Anmeldung in Ihrem Profil. Falls Sie es vergessen, sendet Ihnen „Passwort vergessen?“ auf der Anmeldeseite einen neuen Link.',
    ],
    'email_verification' => [
        'subject' => 'Bestätigen Sie Ihre E-Mail-Adresse bei {$company_name}',
        'body' => "Vielen Dank für Ihre Registrierung bei {\$company_name}. Bitte bestätigen Sie zum Abschluss Ihre E-Mail-Adresse:\n\n"
            ."[E-Mail-Adresse bestätigen]({\$verification_url})\n\n"
            .'Der Link ist 60 Minuten gültig; danach melden Sie sich an und fordern einen neuen an. Wenn Sie sich nicht registriert haben, ignorieren Sie diese E-Mail.',
    ],
    'order_confirmation' => [
        'subject' => 'Bestellbestätigung #{$order_number}',
        'body' => "Wir haben Ihre Bestellung #{\$order_number} vom {\$order_date} erhalten.\n\n{\$order_items}\n\nGesamt: **{\$order_total}**\n\n{\$payment_instructions}",
    ],

    'renewal_reminder' => [
        'subject' => 'Anstehende Verlängerung Ihrer Dienste',
        'body' => "Die folgenden Dienste stehen demnächst zur Verlängerung an.\n\n{\$services}\n\n{\$payment_instructions}\n\nWenn Sie bereits bezahlt haben, ignorieren Sie diese Nachricht bitte.",
    ],
    'overdue_notice' => [
        'subject' => 'Überfällige Zahlung: Dienste warten auf Zahlung',
        'body' => "Das Fälligkeitsdatum der folgenden Dienste ist überschritten. Bitte veranlassen Sie die Zahlung, um eine Sperrung zu vermeiden.\n\n{\$services}\n\n{\$payment_instructions}\n\nWenn Sie bereits bezahlt haben, ignorieren Sie diese Nachricht bitte.",
    ],
    'invoice_created' => [
        'subject' => 'Zahlungsaufforderung {$invoice_number}',
        'body' => "Anbei die Zahlungsaufforderung {\$invoice_number} vom {\$invoice_date} für Ihre Dienste. Dies ist eine Zahlungsaufforderung, keine Rechnung im steuerlichen Sinn.\n\n{$notice}",
    ],
    'invoice_payment_reminder' => [
        'subject' => 'Erinnerung: Zahlungsaufforderung {$invoice_number} ist fällig am {$invoice_due_date}',
        'body' => "Wir möchten Sie daran erinnern, dass die Zahlungsaufforderung {\$invoice_number} am {\$invoice_due_date} fällig ist.\n\n{$notice}",
    ],
    'invoice_overdue_first' => [
        'subject' => 'Zahlungsaufforderung {$invoice_number} ist überfällig',
        'body' => "Die Zahlungsaufforderung {\$invoice_number} war am {\$invoice_due_date} fällig und ist noch offen. Tage im Verzug: {\$days_overdue}.\n\n{$notice}",
    ],
    'invoice_overdue_second' => [
        'subject' => 'Zweite Erinnerung: Zahlungsaufforderung {$invoice_number} ist überfällig',
        'body' => "Wir erinnern Sie zum zweiten Mal daran, dass die Zahlungsaufforderung {\$invoice_number}, fällig am {\$invoice_due_date}, noch offen ist. Bitte begleichen Sie sie, um eine Sperrung Ihrer Dienste zu vermeiden.\n\n{$notice}",
    ],
    'invoice_modified' => [
        'subject' => 'Zahlungsaufforderung {$invoice_number} aktualisiert',
        'body' => "Die Zahlungsaufforderung {\$invoice_number} wurde aktualisiert.\n\n{$notice}",
    ],
    'tax_invoice_issued' => [
        'subject' => 'Rechnung {$tax_invoice_number}',
        'body' => "Vielen Dank für Ihre Zahlung. Anbei erhalten Sie die Rechnung **{\$tax_invoice_number}** vom {\$tax_invoice_date} zur Zahlungsaufforderung {\$invoice_number}.\n\n{\$invoice_items}\n\n{\$invoice_tax}\n\nBezahlter Betrag: **{\$invoice_total}**",
    ],
    'price_change' => [
        'subject' => 'Preisänderung Ihrer Dienste ab {$effective_date}',
        'body' => "Wir möchten Sie informieren, dass sich der Preis der folgenden Dienste für Verlängerungen ab **{\$effective_date}** ändert.\n\n"
            ."{\$price_changes}\n\nBis dahin ändert sich nichts, und bereits ausgestellte Zahlungsaufforderungen bleiben unverändert.\n\n"
            .'Bei Fragen, oder wenn Sie Ihr Paket besprechen möchten, antworten Sie einfach auf diese E-Mail.',
    ],

    'quote' => [
        'subject' => 'Angebot {$quote_number} von {$company_name}',
        'body' => "Vielen Dank für Ihr Interesse. Hier ist unser Angebot **{\$quote_number}**.\n\n{\$quote_intro}\n\n{\$quote_items}\n\n"
            ."Erste Zahlung: **{\$quote_total}**. Das Angebot ist gültig bis {\$quote_valid_until}.\n\n"
            .'[Angebot ansehen und annehmen]({$quote_url})'."\n\n".'Bei Fragen antworten Sie einfach auf diese E-Mail.',
    ],

    'hosting_welcome' => [
        'subject' => 'Ihr Hosting-Konto für {$domain} ist bereit',
        'body' => "Ihr Hosting-Konto für **{\$domain}** ist bereit.\n\n"
            ."Control Panel: {\$control_panel_url}<br>\nBenutzername: {\$username}<br>\nPasswort: {\$password}\n\n"
            ."Richten Sie Ihre Domain auf unsere Nameserver oder ihren A-Eintrag auf {\$server_ip} aus.\n\n"
            .'Paket: {$package}, {$amount} ({$billing_cycle}), nächste Zahlung fällig am {$next_due_date}.',
    ],
    'service_suspension' => [
        'subject' => 'Dienst gesperrt: {$service}',
        'body' => "Ihr Dienst **{\$service}** {\$domain} wurde gesperrt.\n\nGrund: {\$suspension_reason}\n\n{\$payment_instructions}",
    ],
    'service_unsuspension' => [
        'subject' => 'Dienst wieder aktiv: {$service}',
        'body' => 'Ihr Dienst **{$service}** {$domain} ist wieder aktiv.',
    ],

    'cancellation_approved' => [
        'subject' => 'Kündigungsbestätigung: {$service}',
        'body' => "Wir haben Ihre Kündigungsanfrage für **{\$service}** {\$domain} ({\$cancellation_type}) erhalten.\n\n"
            .'Der Dienst endet am {$cancellation_date}, und Sie erhalten keine weiteren Zahlungsaufforderungen dafür.',
    ],

    'cancellation_rejected' => [
        'subject' => 'Ihre Kündigungsanfrage für {$service}',
        'body' => "Wir konnten Ihre Kündigungsanfrage für **{\$service}** {\$domain} nicht ausführen, daher bleibt der Dienst unverändert.\n\n"
            .'Kontaktieren Sie uns, wenn Sie es besprechen möchten.',
    ],

    'domain_registration' => [
        'subject' => 'Domainregistrierung: {$domain}',
        'body' => "Die Domain **{\$domain}** wurde für {\$registration_years} Jahr(e) registriert.\n\nNameserver:<br>\n{\$nameservers}",
    ],
    'domain_renewal' => [
        'subject' => 'Domainverlängerung: {$domain}',
        'body' => 'Die Domain **{$domain}** wurde um {$registration_years} Jahr(e) verlängert. Nächste Zahlung fällig am {$next_due_date}.',
    ],
    'domain_transfer_completed' => [
        'subject' => 'Domaintransfer abgeschlossen: {$domain}',
        'body' => "Der Transfer von **{\$domain}** zu uns ist abgeschlossen. Die Domain läuft am {\$expiry_date} ab.\n\nNameserver:<br>\n{\$nameservers}",
    ],
    'domain_renewal_notice' => [
        'subject' => 'Anstehende Verlängerung Ihrer Domains',
        'body' => "Die folgenden Domains stehen demnächst zur Verlängerung an.\n\n{\$services}\n\n{\$payment_instructions}\n\nWenn Sie bereits bezahlt haben, ignorieren Sie diese Nachricht bitte.",
    ],
    'domain_expired' => [
        'subject' => 'Die Domain {$domain} ist abgelaufen',
        'body' => "Die Domain **{\$domain}** ist am {\$expiry_date} abgelaufen. Verlängern Sie sie bald, sonst kann sie von jedem registriert werden.\n\n{\$payment_instructions}",
    ],

    'usage_warning' => [
        'subject' => '{$domain} erreicht bald das Limit seines Pakets',
        'body' => "Ihr Hosting-Konto **{\$service}** {\$domain} ist nahe an den Limits seines Pakets ({\$package}):\n\n{\$usage}\n\n"
            .'Ist das Limit erreicht, funktionieren Website oder E-Mail möglicherweise nicht mehr. Um das zu vermeiden, können Sie Speicherplatz freigeben oder auf ein größeres Paket wechseln: Klicken Sie auf der Seite des Dienstes im Kundenbereich auf **Upgrade / Downgrade** oder antworten Sie auf diese E-Mail, und wir schlagen Ihnen das passende Paket vor.',
    ],
    'upgrade_approved' => [
        'subject' => 'Paketänderung: {$service}',
        'body' => "Ihr Dienst **{\$service}** {\$domain} wurde auf **{\$new_product}** umgestellt, und das neue Paket gilt bereits.\n\n"
            .'Der neue Preis beträgt **{$new_price}** ({$billing_cycle}) und wird ab dem nächsten Fälligkeitsdatum, {$effective_date}, berechnet. Berechnen wir den Rest des laufenden Zeitraums, erhalten Sie dafür eine separate Zahlungsaufforderung.',
    ],
    'upgrade_rejected' => [
        'subject' => 'Ihre Anfrage zur Paketänderung für {$service}',
        'body' => "Wir konnten Ihren Dienst **{\$service}** {\$domain} nicht auf **{\$new_product}** umstellen, daher bleibt er unverändert.\n\n"
            .'Kontaktieren Sie uns, wenn Sie es besprechen möchten.',
    ],
    'hosting_offer' => [
        'subject' => 'Hosting für {$domain}',
        'body' => "Ihre Domain **{\$domain}** ist bei uns, aber ihre Website und E-Mail werden anderswo gehostet oder sind noch nicht eingerichtet.\n\n"
            ."Wir können sie für Sie hosten, mit E-Mail-Adressen unter Ihrer Domain, sodass Sie alles an einem Ort mit einem Verlängerungsdatum haben.\n\n"
            .'Wenn Sie interessiert sind, antworten Sie auf diese E-Mail, und wir schlagen Ihnen das passende Paket vor.',
    ],
    'domain_transfer_offer' => [
        'subject' => 'Transferieren Sie {$domain} zu {$company_name}',
        'body' => "Wir haben gesehen, dass **{\$domain}** bei einem anderen Anbieter registriert ist. Wir können die Domain zusammen mit Ihren anderen Diensten betreuen, mit einem Verlängerungsdatum und einer Zahlungsaufforderung.\n\n"
            ."Transferpreis: {\$transfer_price}\n\n"
            .'Wenn wir sie transferieren sollen, antworten Sie auf diese E-Mail, und wir senden Ihnen die Schritte.',
    ],
    'domain_winback' => [
        'subject' => 'Sie können {$domain} noch verlängern',
        'body' => 'Die Domain **{$domain}** ist am {$expiry_date} abgelaufen und wurde nicht verlängert. Sie können sie noch für {$renewal_price} verlängern. '
            ."In wenigen Tagen kostet ihre Wiederherstellung deutlich mehr, und später kann sie jeder registrieren.\n\n"
            .'Wenn Sie sie behalten möchten, antworten Sie auf diese E-Mail, und wir verlängern sie sofort.',
    ],
    'ssl_validation' => [
        'subject' => 'Handlung erforderlich: Validierung von {$domain} für das SSL-Zertifikat',
        'body' => "Um das SSL-Zertifikat für **{\$domain}** auszustellen, benötigt die Zertifizierungsstelle einen Nachweis, dass die Domain Ihnen gehört. Methode: {\$validation_method}.\n\n{\$validation}\n\n"
            .'Auf der Seite des Dienstes im Kundenbereich sehen Sie diese Angaben, können eine andere Methode wählen und das Zertifikat nach der Ausstellung herunterladen.',
    ],
    'ssl_issued' => [
        'subject' => 'Das SSL-Zertifikat für {$domain} ist bereit',
        'body' => "Das SSL-Zertifikat für **{\$domain}** wurde ausgestellt und ist gültig bis {\$expiry_date}.\n\n"
            .'Laden Sie es von der Seite des Dienstes im Kundenbereich herunter (Zertifikat, CA-Bundle und privater Schlüssel) und installieren Sie es auf Ihrer Website. Wird Ihre Website bei uns gehostet, antworten Sie auf diese E-Mail, und wir installieren es für Sie.',
    ],
    'ticket_opened' => [
        'subject' => 'Support-Ticket [{$ticket_number}] {$ticket_subject}',
        'body' => "Ihr Support-Ticket **{\$ticket_number}**, „{\$ticket_subject}“, ist eröffnet:\n\n{\$ticket_message}\n\n"
            .'Wir antworten im Ticket und per E-Mail, und Sie können es im Kundenbereich verfolgen und darauf antworten.',
    ],
    'ticket_reply' => [
        'subject' => 'Antwort: [{$ticket_number}] {$ticket_subject}',
        'body' => "Es gibt eine neue Antwort auf Ihr Support-Ticket **{\$ticket_number}**, „{\$ticket_subject}“:\n\n{\$ticket_message}\n\n"
            .'Sie können im Kundenbereich antworten.',
    ],
    'ticket_closed' => [
        'subject' => 'Geschlossen: [{$ticket_number}] {$ticket_subject}',
        'body' => "Ihr Support-Ticket **{\$ticket_number}**, „{\$ticket_subject}“, wurde geschlossen, da wir seit einigen Tagen nichts von Ihnen gehört haben.\n\n"
            .'Wenn Sie noch Hilfe brauchen, antworten Sie im Kundenbereich darauf, und es wird wieder geöffnet.',
    ],
];
