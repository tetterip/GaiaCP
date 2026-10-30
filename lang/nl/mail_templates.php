<?php

// Built-in Dutch text of the client emails (App\Enums\MailTemplate), used for clients whose email language is
// Dutch until the admin saves a Dutch text of their own. Same placeholders as the English text. Payment notices
// are proformas: call them betalingsverzoek, never factuur.

$notice = "{\$invoice_items}\n\n{\$invoice_tax}\n\nTotaal: **{\$invoice_total}**, te betalen vóór {\$invoice_due_date}.\n\n{\$payment_instructions}";

return [
    'greeting' => 'Beste {$client_first_name},',
    'closing' => "Met vriendelijke groet,<br>\n{\$signature}",

    'client_signup' => [
        'subject' => 'Welkom bij {$company_name}',
        'body' => 'Bedankt dat u voor {$company_name} hebt gekozen. Uw account is aangemaakt met het e-mailadres {$client_email}.',
    ],
    'client_area_invite' => [
        'subject' => 'Uw klantomgeving bij {$company_name}',
        'body' => "U kunt nu uw diensten, domeinen en betalingsverzoeken bekijken in de klantomgeving van {\$company_name}.\n\n"
            ."[Stel uw wachtwoord in]({\$set_password_url})\n\nDe link werkt één keer, gedurende 48 uur. Uw gebruikersnaam is uw e-mailadres, {\$client_email}. "
            .'Daarna logt u in op {$client_area_url}, waar “Wachtwoord vergeten?” u een nieuwe link stuurt.',
    ],
    'client_area_login' => [
        'subject' => 'Uw inloggegevens voor de klantomgeving van {$company_name}',
        'body' => "Welkom bij {\$company_name}. U kunt nu uw diensten, domeinen en betalingsverzoeken bekijken in onze klantomgeving.\n\n"
            ."Adres: {\$client_area_url}\n\nGebruikersnaam: {\$client_email}\n\nWachtwoord: {\$password}\n\n"
            .'Wijzig het wachtwoord in uw profiel zodra u bent ingelogd. Bent u het vergeten, dan stuurt “Wachtwoord vergeten?” op de inlogpagina u een nieuwe link.',
    ],
    'email_verification' => [
        'subject' => 'Bevestig uw e-mailadres bij {$company_name}',
        'body' => "Bedankt voor uw registratie bij {\$company_name}. Bevestig uw e-mailadres om deze af te ronden:\n\n"
            ."[E-mailadres bevestigen]({\$verification_url})\n\n"
            .'De link werkt 60 minuten; log daarna in en vraag een nieuwe aan. Hebt u zich niet zelf geregistreerd, negeer deze e-mail dan.',
    ],
    'order_confirmation' => [
        'subject' => 'Bevestiging van bestelling #{$order_number}',
        'body' => "We hebben uw bestelling #{\$order_number} van {\$order_date} ontvangen.\n\n{\$order_items}\n\nTotaal: **{\$order_total}**\n\n{\$payment_instructions}",
    ],

    'renewal_reminder' => [
        'subject' => 'Binnenkort verlenging van uw diensten',
        'body' => "De onderstaande diensten naderen hun verlengingsdatum.\n\n{\$services}\n\n{\$payment_instructions}\n\nHebt u al betaald, dan kunt u dit bericht negeren.",
    ],
    'overdue_notice' => [
        'subject' => 'Achterstallige betaling: diensten wachten op betaling',
        'body' => "De vervaldatum van de onderstaande diensten is verstreken. Wij verzoeken u de betaling te voldoen, zodat opschorting kan worden voorkomen.\n\n{\$services}\n\n{\$payment_instructions}\n\nHebt u al betaald, dan kunt u dit bericht negeren.",
    ],
    'invoice_created' => [
        'subject' => 'Betalingsverzoek {$invoice_number}',
        'body' => "Hierbij ontvangt u betalingsverzoek {\$invoice_number} van {\$invoice_date} voor uw diensten. Dit is een betalingsverzoek en geen factuur.\n\n{$notice}",
    ],
    'invoice_payment_reminder' => [
        'subject' => 'Herinnering: betalingsverzoek {$invoice_number} is te betalen vóór {$invoice_due_date}',
        'body' => "Wij herinneren u eraan dat betalingsverzoek {\$invoice_number} te betalen is vóór {\$invoice_due_date}.\n\n{$notice}",
    ],
    'invoice_overdue_first' => [
        'subject' => 'Betalingsverzoek {$invoice_number} is achterstallig',
        'body' => "Betalingsverzoek {\$invoice_number} moest worden betaald vóór {\$invoice_due_date} en staat nog open. Dagen achterstallig: {\$days_overdue}.\n\n{$notice}",
    ],
    'invoice_overdue_second' => [
        'subject' => 'Tweede herinnering: betalingsverzoek {$invoice_number} is achterstallig',
        'body' => "Wij herinneren u er voor de tweede keer aan dat betalingsverzoek {\$invoice_number}, te betalen vóór {\$invoice_due_date}, nog openstaat. Wij verzoeken u het te voldoen, zodat opschorting van uw diensten kan worden voorkomen.\n\n{$notice}",
    ],
    'invoice_modified' => [
        'subject' => 'Betalingsverzoek {$invoice_number} bijgewerkt',
        'body' => "Betalingsverzoek {\$invoice_number} is bijgewerkt.\n\n{$notice}",
    ],
    'tax_invoice_issued' => [
        'subject' => 'Factuur {$tax_invoice_number}',
        'body' => "Bedankt voor uw betaling. In de bijlage vindt u factuur **{\$tax_invoice_number}** van {\$tax_invoice_date}, voor betalingsverzoek {\$invoice_number}.\n\n{\$invoice_items}\n\n{\$invoice_tax}\n\nTotaal betaald: **{\$invoice_total}**",
    ],
    'price_change' => [
        'subject' => 'Prijswijziging van uw diensten vanaf {$effective_date}',
        'body' => "Wij laten u weten dat de prijs van de onderstaande diensten verandert voor verlengingen die vervallen vanaf **{\$effective_date}**.\n\n"
            ."{\$price_changes}\n\nTot die datum verandert er niets, en betalingsverzoeken die al zijn uitgegeven blijven zoals ze zijn.\n\n"
            .'Hebt u vragen, of wilt u uw pakket bespreken, beantwoord dan deze e-mail.',
    ],

    'quote' => [
        'subject' => 'Offerte {$quote_number} van {$company_name}',
        'body' => "Bedankt voor uw interesse. Hierbij onze offerte **{\$quote_number}**.\n\n{\$quote_intro}\n\n{\$quote_items}\n\n"
            ."Eerste betaling: **{\$quote_total}**. De offerte is geldig tot {\$quote_valid_until}.\n\n"
            .'[Bekijk en accepteer de offerte]({$quote_url})'."\n\n".'Hebt u vragen, beantwoord dan deze e-mail.',
    ],

    'hosting_welcome' => [
        'subject' => 'Uw hostingaccount voor {$domain} is klaar',
        'body' => "Uw hostingaccount voor **{\$domain}** is klaar.\n\n"
            ."Configuratiescherm: {\$control_panel_url}<br>\nGebruikersnaam: {\$username}<br>\nWachtwoord: {\$password}\n\n"
            ."Laat uw domein naar onze nameservers wijzen, of het A-record naar het adres {\$server_ip}.\n\n"
            .'Pakket: {$package}, {$amount} ({$billing_cycle}), volgende betaling vóór {$next_due_date}.',
    ],
    'service_suspension' => [
        'subject' => 'Dienst opgeschort: {$service}',
        'body' => "Uw dienst **{\$service}** {\$domain} is opgeschort.\n\nReden: {\$suspension_reason}\n\n{\$payment_instructions}",
    ],
    'service_unsuspension' => [
        'subject' => 'Dienst weer actief: {$service}',
        'body' => 'Uw dienst **{$service}** {$domain} is weer actief.',
    ],

    'cancellation_approved' => [
        'subject' => 'Bevestiging van opzegging: {$service}',
        'body' => "We hebben het opzegverzoek voor uw dienst **{\$service}** {\$domain} ontvangen ({\$cancellation_type}).\n\n"
            .'De dienst eindigt op {$cancellation_date} en u ontvangt er geen betalingsverzoeken meer voor.',
    ],

    'cancellation_rejected' => [
        'subject' => 'Uw opzegverzoek voor {$service}',
        'body' => "We konden het opzegverzoek voor uw dienst **{\$service}** {\$domain} niet uitvoeren, dus de dienst blijft zoals hij is.\n\n"
            .'Neem contact met ons op als u dit wilt bespreken.',
    ],

    'domain_registration' => [
        'subject' => 'Domeinregistratie: {$domain}',
        'body' => "Het domein **{\$domain}** is geregistreerd. Jaren geregistreerd: {\$registration_years}.\n\nNameservers:<br>\n{\$nameservers}",
    ],
    'domain_renewal' => [
        'subject' => 'Domeinverlenging: {$domain}',
        'body' => 'Het domein **{$domain}** is verlengd. Jaren verlengd: {$registration_years}. Volgende betaling vóór {$next_due_date}.',
    ],
    'domain_transfer_completed' => [
        'subject' => 'Domeinverhuizing voltooid: {$domain}',
        'body' => "De verhuizing van **{\$domain}** naar ons is voltooid. Het verloopt op {\$expiry_date}.\n\nNameservers:<br>\n{\$nameservers}",
    ],
    'domain_renewal_notice' => [
        'subject' => 'Binnenkort verlenging van uw domeinen',
        'body' => "De onderstaande domeinen naderen hun verlengingsdatum.\n\n{\$services}\n\n{\$payment_instructions}\n\nHebt u al betaald, dan kunt u dit bericht negeren.",
    ],
    'domain_expired' => [
        'subject' => 'Het domein {$domain} is verlopen',
        'body' => "Het domein **{\$domain}** is verlopen op {\$expiry_date}. Verleng het zo snel mogelijk, anders kan iedereen het registreren.\n\n{\$payment_instructions}",
    ],

    'usage_warning' => [
        'subject' => '{$domain} nadert de limiet van zijn pakket',
        'body' => "Uw hostingaccount **{\$service}** {\$domain} nadert de limieten van zijn pakket ({\$package}):\n\n{\$usage}\n\n"
            .'Wanneer de limiet is bereikt, kunnen de website of de e-mail stoppen met werken. Om dat te voorkomen kunt u ruimte vrijmaken of overstappen op een groter pakket: druk op **Upgrade / downgrade** op de pagina van de dienst in de klantomgeving, of beantwoord deze e-mail en wij stellen het pakket voor dat bij u past.',
    ],
    'upgrade_approved' => [
        'subject' => 'Pakketwijziging: {$service}',
        'body' => "Uw dienst **{\$service}** {\$domain} is verplaatst naar **{\$new_product}** en het nieuwe pakket geldt al.\n\n"
            .'De nieuwe prijs is **{$new_price}** ({$billing_cycle}) en wordt berekend vanaf de volgende vervaldatum, {$effective_date}. Als we het restant van de huidige periode in rekening brengen, ontvangt u een apart betalingsverzoek.',
    ],
    'upgrade_rejected' => [
        'subject' => 'Uw verzoek om pakketwijziging voor {$service}',
        'body' => "We konden uw dienst **{\$service}** {\$domain} niet verplaatsen naar **{\$new_product}**, dus hij blijft zoals hij is.\n\n"
            .'Neem contact met ons op als u dit wilt bespreken.',
    ],
    'hosting_offer' => [
        'subject' => 'Hosting voor {$domain}',
        'body' => "Uw domein **{\$domain}** staat bij ons, maar de website en e-mail ervan worden elders gehost of zijn nog niet ingericht.\n\n"
            ."Wij kunnen ze voor u hosten, met e-mailadressen op uw domein, zodat u alles op één plek hebt met één verlengingsdatum.\n\n"
            .'Hebt u interesse, beantwoord dan deze e-mail en wij stellen het pakket voor dat bij u past.',
    ],
    'domain_transfer_offer' => [
        'subject' => 'Verhuis {$domain} naar {$company_name}',
        'body' => "We zagen dat **{\$domain}** bij een andere provider is geregistreerd. Wij kunnen het overnemen samen met uw andere diensten, met één verlengingsdatum en één betalingsverzoek.\n\n"
            ."Verhuisprijs: {\$transfer_price}\n\n"
            .'Wilt u dat wij het verhuizen, beantwoord dan deze e-mail en wij sturen u de stappen.',
    ],
    'domain_winback' => [
        'subject' => 'U kunt {$domain} nog verlengen',
        'body' => 'Het domein **{$domain}** is verlopen op {$expiry_date} en is niet verlengd. U kunt het nog verlengen voor {$renewal_price}. '
            ."Over enkele dagen kost het herstellen ervan veel meer, en later kan iedereen het registreren.\n\n"
            .'Wilt u het behouden, beantwoord dan deze e-mail en wij verlengen het meteen.',
    ],
    'ssl_validation' => [
        'subject' => 'Actie vereist: bevestig {$domain} voor het SSL-certificaat',
        'body' => "Om het SSL-certificaat van **{\$domain}** uit te geven, heeft de certificaatautoriteit bewijs nodig dat het domein van u is. Methode: {\$validation_method}.\n\n{\$validation}\n\n"
            .'U ziet dit ook op de pagina van de dienst in de klantomgeving, waar u een andere methode kunt kiezen en het certificaat kunt downloaden zodra het is uitgegeven.',
    ],
    'ssl_issued' => [
        'subject' => 'Het SSL-certificaat van {$domain} is klaar',
        'body' => "Het SSL-certificaat van **{\$domain}** is uitgegeven en is geldig tot {\$expiry_date}.\n\n"
            .'Download het vanaf de pagina van de dienst in de klantomgeving (certificaat, CA-bundel en privésleutel) en installeer het op uw website. Wordt uw website bij ons gehost, beantwoord dan deze e-mail en wij installeren het voor u.',
    ],
    'ticket_opened' => [
        'subject' => 'Supportticket [{$ticket_number}] {$ticket_subject}',
        'body' => "Uw supportticket **{\$ticket_number}**, “{\$ticket_subject}”, is geopend:\n\n{\$ticket_message}\n\n"
            .'Wij beantwoorden het ticket ook per e-mail, en u kunt het volgen en erop reageren vanuit de klantomgeving.',
    ],
    'ticket_reply' => [
        'subject' => 'Antwoord: [{$ticket_number}] {$ticket_subject}',
        'body' => "Er is een nieuw antwoord op uw supportticket **{\$ticket_number}**, “{\$ticket_subject}”:\n\n{\$ticket_message}\n\n"
            .'U kunt reageren vanuit de klantomgeving.',
    ],
    'ticket_closed' => [
        'subject' => 'Gesloten: [{$ticket_number}] {$ticket_subject}',
        'body' => "Uw supportticket **{\$ticket_number}**, “{\$ticket_subject}”, is gesloten, omdat we al enkele dagen niets van u hebben gehoord.\n\n"
            .'Hebt u nog hulp nodig, reageer er dan op vanuit de klantomgeving en het wordt weer geopend.',
    ],
];
