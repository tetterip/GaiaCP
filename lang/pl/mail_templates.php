<?php

// Built-in Polish text of the client emails (App\Enums\MailTemplate), used for clients whose email language is
// Polish until the admin saves a Polish text of their own. Same placeholders as the English text. Payment notices
// are proformas: call them wezwanie do zapłaty, never faktura.

$notice = "{\$invoice_items}\n\n{\$invoice_tax}\n\nRazem: **{\$invoice_total}**, płatne do {\$invoice_due_date}.\n\n{\$payment_instructions}";

return [
    'greeting' => 'Dzień dobry {$client_first_name},',
    'closing' => "Z poważaniem,<br>\n{\$signature}",

    'client_signup' => [
        'subject' => 'Witamy w {$company_name}',
        'body' => 'Dziękujemy za wybranie {$company_name}. Konto zostało utworzone z adresem e-mail {$client_email}.',
    ],
    'client_area_invite' => [
        'subject' => 'Panel klienta w {$company_name}',
        'body' => "W panelu klienta {\$company_name} mogą Państwo teraz przeglądać swoje usługi, domeny i wezwania do zapłaty.\n\n"
            ."[Ustaw hasło]({\$set_password_url})\n\nLink działa jeden raz, przez 48 godzin. Nazwą użytkownika jest Państwa adres e-mail, {\$client_email}. "
            .'Następnie logują się Państwo pod adresem {$client_area_url}, gdzie „Nie pamiętasz hasła?” wyśle nowy link.',
    ],
    'client_area_login' => [
        'subject' => 'Dane logowania do panelu klienta {$company_name}',
        'body' => "Witamy w {\$company_name}. W naszym panelu klienta mogą Państwo teraz przeglądać swoje usługi, domeny i wezwania do zapłaty.\n\n"
            ."Adres: {\$client_area_url}\n\nNazwa użytkownika: {\$client_email}\n\nHasło: {\$password}\n\n"
            .'Prosimy zmienić hasło w profilu zaraz po zalogowaniu. W razie zapomnienia hasła „Nie pamiętasz hasła?” na stronie logowania wyśle nowy link.',
    ],
    'email_verification' => [
        'subject' => 'Prosimy potwierdzić adres e-mail w {$company_name}',
        'body' => "Dziękujemy za rejestrację w {\$company_name}. Aby ją zakończyć, prosimy potwierdzić adres e-mail:\n\n"
            ."[Potwierdź adres e-mail]({\$verification_url})\n\n"
            .'Link działa przez 60 minut; później prosimy się zalogować i poprosić o nowy. Jeśli to nie Państwo się rejestrowali, prosimy zignorować tę wiadomość.',
    ],
    'order_confirmation' => [
        'subject' => 'Potwierdzenie zamówienia #{$order_number}',
        'body' => "Otrzymaliśmy Państwa zamówienie #{\$order_number} z dnia {\$order_date}.\n\n{\$order_items}\n\nRazem: **{\$order_total}**\n\n{\$payment_instructions}",
    ],

    'renewal_reminder' => [
        'subject' => 'Zbliżające się odnowienie Państwa usług',
        'body' => "Zbliża się termin odnowienia poniższych usług.\n\n{\$services}\n\n{\$payment_instructions}\n\nJeśli płatność została już dokonana, prosimy zignorować tę wiadomość.",
    ],
    'overdue_notice' => [
        'subject' => 'Zaległa płatność: usługi oczekujące na płatność',
        'body' => "Termin płatności poniższych usług już minął. Prosimy o uregulowanie płatności, aby uniknąć ich zawieszenia.\n\n{\$services}\n\n{\$payment_instructions}\n\nJeśli płatność została już dokonana, prosimy zignorować tę wiadomość.",
    ],
    'invoice_created' => [
        'subject' => 'Wezwanie do zapłaty {$invoice_number}',
        'body' => "Przesyłamy wezwanie do zapłaty {\$invoice_number} z dnia {\$invoice_date} za Państwa usługi. Jest to wezwanie do zapłaty, a nie faktura.\n\n{$notice}",
    ],
    'invoice_payment_reminder' => [
        'subject' => 'Przypomnienie: wezwanie do zapłaty {$invoice_number} jest płatne do {$invoice_due_date}',
        'body' => "Przypominamy, że wezwanie do zapłaty {\$invoice_number} jest płatne do {\$invoice_due_date}.\n\n{$notice}",
    ],
    'invoice_overdue_first' => [
        'subject' => 'Wezwanie do zapłaty {$invoice_number} jest po terminie',
        'body' => "Wezwanie do zapłaty {\$invoice_number} było płatne do {\$invoice_due_date} i pozostaje nieopłacone. Dni opóźnienia: {\$days_overdue}.\n\n{$notice}",
    ],
    'invoice_overdue_second' => [
        'subject' => 'Drugie przypomnienie: wezwanie do zapłaty {$invoice_number} jest po terminie',
        'body' => "Przypominamy po raz drugi, że wezwanie do zapłaty {\$invoice_number}, płatne do {\$invoice_due_date}, pozostaje nieopłacone. Prosimy o jego uregulowanie, aby uniknąć zawieszenia Państwa usług.\n\n{$notice}",
    ],
    'invoice_modified' => [
        'subject' => 'Aktualizacja wezwania do zapłaty {$invoice_number}',
        'body' => "Wezwanie do zapłaty {\$invoice_number} zostało zaktualizowane.\n\n{$notice}",
    ],
    'tax_invoice_issued' => [
        'subject' => 'Faktura {$tax_invoice_number}',
        'body' => "Dziękujemy za płatność. W załączniku przesyłamy fakturę **{\$tax_invoice_number}** z dnia {\$tax_invoice_date} do wezwania do zapłaty {\$invoice_number}.\n\n{\$invoice_items}\n\n{\$invoice_tax}\n\nZapłacono łącznie: **{\$invoice_total}**",
    ],
    'price_change' => [
        'subject' => 'Zmiana ceny Państwa usług od {$effective_date}',
        'body' => "Informujemy, że cena poniższych usług zmienia się dla odnowień z terminem od **{\$effective_date}**.\n\n"
            ."{\$price_changes}\n\nDo tego czasu nic się nie zmienia, a już wystawione wezwania do zapłaty pozostają bez zmian.\n\n"
            .'W razie pytań lub chęci omówienia pakietu prosimy odpowiedzieć na tę wiadomość.',
    ],

    'quote' => [
        'subject' => 'Oferta {$quote_number} od {$company_name}',
        'body' => "Dziękujemy za zainteresowanie. Poniżej nasza oferta **{\$quote_number}**.\n\n{\$quote_intro}\n\n{\$quote_items}\n\n"
            ."Pierwsza płatność: **{\$quote_total}**. Oferta jest ważna do {\$quote_valid_until}.\n\n"
            .'[Zobacz i przyjmij ofertę]({$quote_url})'."\n\n".'W razie pytań prosimy odpowiedzieć na tę wiadomość.',
    ],

    'hosting_welcome' => [
        'subject' => 'Konto hostingowe dla {$domain} jest gotowe',
        'body' => "Państwa konto hostingowe dla **{\$domain}** jest gotowe.\n\n"
            ."Panel sterowania: {\$control_panel_url}<br>\nNazwa użytkownika: {\$username}<br>\nHasło: {\$password}\n\n"
            ."Prosimy skierować domenę na nasze serwery nazw lub ustawić jej rekord A na adres {\$server_ip}.\n\n"
            .'Pakiet: {$package}, {$amount} ({$billing_cycle}), następna płatność do {$next_due_date}.',
    ],
    'service_suspension' => [
        'subject' => 'Zawieszenie usługi: {$service}',
        'body' => "Państwa usługa **{\$service}** {\$domain} została zawieszona.\n\nPowód: {\$suspension_reason}\n\n{\$payment_instructions}",
    ],
    'service_unsuspension' => [
        'subject' => 'Przywrócenie usługi: {$service}',
        'body' => 'Państwa usługa **{$service}** {$domain} jest znów aktywna.',
    ],

    'cancellation_approved' => [
        'subject' => 'Potwierdzenie rezygnacji: {$service}',
        'body' => "Otrzymaliśmy prośbę o rezygnację z usługi **{\$service}** {\$domain} ({\$cancellation_type}).\n\n"
            .'Usługa kończy się {$cancellation_date} i nie będą już Państwo otrzymywać za nią wezwań do zapłaty.',
    ],

    'cancellation_rejected' => [
        'subject' => 'Prośba o rezygnację z {$service}',
        'body' => "Nie mogliśmy zrealizować prośby o rezygnację z usługi **{\$service}** {\$domain}, więc usługa pozostaje bez zmian.\n\n"
            .'Prosimy o kontakt, jeśli chcą Państwo to omówić.',
    ],

    'domain_registration' => [
        'subject' => 'Rejestracja domeny: {$domain}',
        'body' => "Domena **{\$domain}** została zarejestrowana. Liczba lat rejestracji: {\$registration_years}.\n\nSerwery nazw:<br>\n{\$nameservers}",
    ],
    'domain_renewal' => [
        'subject' => 'Odnowienie domeny: {$domain}',
        'body' => 'Domena **{$domain}** została odnowiona. Liczba lat odnowienia: {$registration_years}. Następna płatność do {$next_due_date}.',
    ],
    'domain_transfer_completed' => [
        'subject' => 'Transfer domeny zakończony: {$domain}',
        'body' => "Transfer domeny **{\$domain}** do nas został zakończony. Domena wygasa {\$expiry_date}.\n\nSerwery nazw:<br>\n{\$nameservers}",
    ],
    'domain_renewal_notice' => [
        'subject' => 'Zbliżające się odnowienie Państwa domen',
        'body' => "Zbliża się termin odnowienia poniższych domen.\n\n{\$services}\n\n{\$payment_instructions}\n\nJeśli płatność została już dokonana, prosimy zignorować tę wiadomość.",
    ],
    'domain_expired' => [
        'subject' => 'Domena {$domain} wygasła',
        'body' => "Domena **{\$domain}** wygasła {\$expiry_date}. Prosimy odnowić ją jak najszybciej, w przeciwnym razie może ją zarejestrować ktokolwiek.\n\n{\$payment_instructions}",
    ],

    'usage_warning' => [
        'subject' => '{$domain} zbliża się do limitu swojego pakietu',
        'body' => "Państwa konto hostingowe **{\$service}** {\$domain} zbliża się do limitów swojego pakietu ({\$package}):\n\n{\$usage}\n\n"
            .'Po osiągnięciu limitu strona WWW lub poczta mogą przestać działać. Aby tego uniknąć, można zwolnić miejsce lub przejść na większy pakiet: prosimy kliknąć **Zmiana pakietu** na stronie usługi w panelu klienta lub odpowiedzieć na tę wiadomość, a zaproponujemy odpowiedni pakiet.',
    ],
    'upgrade_approved' => [
        'subject' => 'Zmiana pakietu: {$service}',
        'body' => "Państwa usługa **{\$service}** {\$domain} została przeniesiona na **{\$new_product}**, a nowy pakiet już obowiązuje.\n\n"
            .'Jej nowa cena to **{$new_price}** ({$billing_cycle}), naliczana od następnego terminu płatności, {$effective_date}. Jeśli naliczymy różnicę za bieżący okres, otrzymają Państwo osobne wezwanie do zapłaty.',
    ],
    'upgrade_rejected' => [
        'subject' => 'Prośba o zmianę pakietu dla {$service}',
        'body' => "Nie mogliśmy przenieść usługi **{\$service}** {\$domain} na **{\$new_product}**, więc pozostaje ona bez zmian.\n\n"
            .'Prosimy o kontakt, jeśli chcą Państwo to omówić.',
    ],
    'hosting_offer' => [
        'subject' => 'Hosting dla {$domain}',
        'body' => "Państwa domena **{\$domain}** jest u nas, ale jej strona WWW i poczta są utrzymywane gdzie indziej albo nie zostały jeszcze uruchomione.\n\n"
            ."Możemy je u siebie utrzymywać, z adresami e-mail w Państwa domenie, aby wszystko było w jednym miejscu z jedną datą odnowienia.\n\n"
            .'Jeśli są Państwo zainteresowani, prosimy odpowiedzieć na tę wiadomość, a zaproponujemy odpowiedni pakiet.',
    ],
    'domain_transfer_offer' => [
        'subject' => 'Prosimy przenieść {$domain} do {$company_name}',
        'body' => "Zauważyliśmy, że domena **{\$domain}** jest zarejestrowana u innego dostawcy. Możemy ją przejąć razem z pozostałymi Państwa usługami, z jedną datą odnowienia i jednym wezwaniem do zapłaty.\n\n"
            ."Cena transferu: {\$transfer_price}\n\n"
            .'Jeśli chcą Państwo ją przenieść, prosimy odpowiedzieć na tę wiadomość, a prześlemy kolejne kroki.',
    ],
    'domain_winback' => [
        'subject' => 'Domenę {$domain} wciąż można odnowić',
        'body' => 'Domena **{$domain}** wygasła {$expiry_date} i nie została odnowiona. Wciąż można ją odnowić za {$renewal_price}. '
            ."Za kilka dni jej odzyskanie będzie kosztować znacznie więcej, a później może ją zarejestrować ktokolwiek.\n\n"
            .'Jeśli chcą Państwo ją zachować, prosimy odpowiedzieć na tę wiadomość, a odnowimy ją od razu.',
    ],
    'ssl_validation' => [
        'subject' => 'Wymagane działanie: weryfikacja {$domain} dla certyfikatu SSL',
        'body' => "Aby wystawić certyfikat SSL dla **{\$domain}**, urząd certyfikacji potrzebuje potwierdzenia, że domena należy do Państwa. Sposób: {\$validation_method}.\n\n{\$validation}\n\n"
            .'Te informacje, wybór innego sposobu oraz pobranie certyfikatu po jego wystawieniu są dostępne na stronie usługi w panelu klienta.',
    ],
    'ssl_issued' => [
        'subject' => 'Certyfikat SSL dla {$domain} jest gotowy',
        'body' => "Certyfikat SSL dla **{\$domain}** został wystawiony i jest ważny do {\$expiry_date}.\n\n"
            .'Prosimy pobrać go ze strony usługi w panelu klienta (certyfikat, CA bundle i klucz prywatny) i zainstalować na swojej stronie. Jeśli strona jest utrzymywana u nas, prosimy odpowiedzieć na tę wiadomość, a zainstalujemy go sami.',
    ],
    'ticket_opened' => [
        'subject' => 'Zgłoszenie [{$ticket_number}] {$ticket_subject}',
        'body' => "Państwa zgłoszenie **{\$ticket_number}**, „{\$ticket_subject}”, jest otwarte:\n\n{\$ticket_message}\n\n"
            .'Odpowiadamy na zgłoszenie także e-mailem, a w panelu klienta można je śledzić i na nie odpowiadać.',
    ],
    'ticket_reply' => [
        'subject' => 'Odpowiedź: [{$ticket_number}] {$ticket_subject}',
        'body' => "W Państwa zgłoszeniu **{\$ticket_number}**, „{\$ticket_subject}”, jest nowa odpowiedź:\n\n{\$ticket_message}\n\n"
            .'Odpowiedzieć można w panelu klienta.',
    ],
    'ticket_closed' => [
        'subject' => 'Zamknięte: [{$ticket_number}] {$ticket_subject}',
        'body' => "Państwa zgłoszenie **{\$ticket_number}**, „{\$ticket_subject}”, zostało zamknięte, ponieważ od kilku dni nie otrzymaliśmy odpowiedzi.\n\n"
            .'Jeśli pomoc jest nadal potrzebna, prosimy odpowiedzieć na nie w panelu klienta, a zostanie ponownie otwarte.',
    ],
];
