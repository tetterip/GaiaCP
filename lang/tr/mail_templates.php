<?php

// Built-in Turkish text of the client emails (App\Enums\MailTemplate), used for clients whose email language is
// Turkish until the admin saves a Turkish text of their own. Same placeholders as the English text. Payment notices
// are proformas: call them ödeme bildirimi, never fatura.

$notice = "{\$invoice_items}\n\n{\$invoice_tax}\n\nToplam: **{\$invoice_total}**, son ödeme tarihi {\$invoice_due_date}.\n\n{\$payment_instructions}";

return [
    'greeting' => 'Merhaba {$client_first_name},',
    'closing' => "Teşekkürler,<br>\n{\$signature}",

    'client_signup' => [
        'subject' => '{$company_name} ailesine hoş geldiniz',
        'body' => 'Bizi tercih ettiğiniz için {$company_name} olarak teşekkür ederiz. Hesabınız {$client_email} e-posta adresiyle oluşturuldu.',
    ],
    'client_area_invite' => [
        'subject' => '{$company_name} müşteri paneliniz',
        'body' => "Artık hizmetlerinizi, alan adlarınızı ve ödeme bildirimlerinizi {\$company_name} müşteri panelinde görebilirsiniz.\n\n"
            ."[Parolanızı belirleyin]({\$set_password_url})\n\nBağlantı bir kez, 48 saat boyunca çalışır. Kullanıcı adınız e-posta adresinizdir: {\$client_email}. "
            .'Daha sonra {$client_area_url} adresinden giriş yapın; orada "Şifrenizi mi unuttunuz?" size yeni bir bağlantı gönderir.',
    ],
    'client_area_login' => [
        'subject' => '{$company_name} müşteri paneli giriş bilgileriniz',
        'body' => "{\$company_name} ailesine hoş geldiniz. Artık hizmetlerinizi, alan adlarınızı ve ödeme bildirimlerinizi müşteri panelimizde görebilirsiniz.\n\n"
            ."Adres: {\$client_area_url}\n\nKullanıcı adı: {\$client_email}\n\nParola: {\$password}\n\n"
            .'Lütfen giriş yaptıktan sonra parolanızı profilinizden değiştirin. Unutursanız giriş sayfasındaki "Şifrenizi mi unuttunuz?" size yeni bir bağlantı gönderir.',
    ],
    'email_verification' => [
        'subject' => '{$company_name} için e-posta adresinizi doğrulayın',
        'body' => "{\$company_name} kaydınız için teşekkür ederiz. Tamamlamak için lütfen e-posta adresinizi doğrulayın:\n\n"
            ."[E-posta adresimi doğrula]({\$verification_url})\n\n"
            .'Bağlantı 60 dakika çalışır; sonrasında giriş yapıp yenisini isteyin. Kayıt olan siz değilseniz lütfen bu e-postayı yok sayın.',
    ],
    'order_confirmation' => [
        'subject' => 'Sipariş onayı #{$order_number}',
        'body' => "{\$order_date} tarihli #{\$order_number} numaralı siparişinizi aldık.\n\n{\$order_items}\n\nToplam: **{\$order_total}**\n\n{\$payment_instructions}",
    ],

    'renewal_reminder' => [
        'subject' => 'Hizmetlerinizin yaklaşan yenilemesi',
        'body' => "Aşağıdaki hizmetlerin yenileme tarihi yaklaşıyor.\n\n{\$services}\n\n{\$payment_instructions}\n\nÖdemeyi zaten yaptıysanız lütfen bu mesajı dikkate almayın.",
    ],
    'overdue_notice' => [
        'subject' => 'Gecikmiş ödeme: ödeme bekleyen hizmetler',
        'body' => "Aşağıdaki hizmetlerin vade tarihi geçti. Askıya alınmamaları için lütfen ödemeyi yapın.\n\n{\$services}\n\n{\$payment_instructions}\n\nÖdemeyi zaten yaptıysanız lütfen bu mesajı dikkate almayın.",
    ],
    'invoice_created' => [
        'subject' => 'Ödeme bildirimi {$invoice_number}',
        'body' => "Hizmetleriniz için {\$invoice_date} tarihli {\$invoice_number} numaralı ödeme bildirimi. Bu bir ödeme bildirimidir, vergi belgesi değildir.\n\n{$notice}",
    ],
    'invoice_payment_reminder' => [
        'subject' => 'Hatırlatma: {$invoice_number} numaralı ödeme bildiriminin son ödeme tarihi {$invoice_due_date}',
        'body' => "{\$invoice_number} numaralı ödeme bildiriminin son ödeme tarihinin {\$invoice_due_date} olduğunu hatırlatırız.\n\n{$notice}",
    ],
    'invoice_overdue_first' => [
        'subject' => '{$invoice_number} numaralı ödeme bildiriminin vadesi geçti',
        'body' => "{\$invoice_number} numaralı ödeme bildiriminin son ödeme tarihi {\$invoice_due_date} idi ve ödeme {\$days_overdue} gün gecikti.\n\n{$notice}",
    ],
    'invoice_overdue_second' => [
        'subject' => 'İkinci hatırlatma: {$invoice_number} numaralı ödeme bildiriminin vadesi geçti',
        'body' => "Son ödeme tarihi {\$invoice_due_date} olan {\$invoice_number} numaralı ödeme bildiriminin hâlâ ödenmediğini ikinci kez hatırlatırız. Hizmetlerinizin askıya alınmaması için lütfen ödeyin.\n\n{$notice}",
    ],
    'invoice_modified' => [
        'subject' => '{$invoice_number} numaralı ödeme bildirimi güncellendi',
        'body' => "{\$invoice_number} numaralı ödeme bildirimi güncellendi.\n\n{$notice}",
    ],
    'tax_invoice_issued' => [
        'subject' => 'Fatura {$tax_invoice_number}',
        'body' => "Ödemeniz için teşekkür ederiz. {\$invoice_number} numaralı ödeme bildirimi için {\$tax_invoice_date} tarihli **{\$tax_invoice_number}** numaralı fatura ektedir.\n\n{\$invoice_items}\n\n{\$invoice_tax}\n\nÖdenen toplam: **{\$invoice_total}**",
    ],
    'price_change' => [
        'subject' => 'Hizmetlerinizin fiyatı {$effective_date} tarihinden itibaren değişiyor',
        'body' => "Aşağıdaki hizmetlerin fiyatının, vadesi **{\$effective_date}** tarihinden itibaren gelen yenilemeler için değiştiğini bildirmek isteriz.\n\n"
            ."{\$price_changes}\n\nO zamana kadar hiçbir şey değişmez ve zaten düzenlenmiş ödeme bildirimleri olduğu gibi kalır.\n\n"
            .'Sorularınız varsa veya paketiniz hakkında konuşmak isterseniz lütfen bu e-postayı yanıtlayın.',
    ],

    'quote' => [
        'subject' => '{$company_name} teklifi {$quote_number}',
        'body' => "İlginiz için teşekkür ederiz. Teklifimiz **{\$quote_number}** aşağıdadır.\n\n{\$quote_intro}\n\n{\$quote_items}\n\n"
            ."İlk ödeme: **{\$quote_total}**. Teklif {\$quote_valid_until} tarihine kadar geçerlidir.\n\n"
            .'[Teklifi görüntüleyin ve kabul edin]({$quote_url})'."\n\n".'Sorularınız varsa bu e-postayı yanıtlamanız yeterli.',
    ],

    'hosting_welcome' => [
        'subject' => '{$domain} için hosting hesabınız hazır',
        'body' => "**{\$domain}** için hosting hesabınız hazır.\n\n"
            ."Kontrol paneli: {\$control_panel_url}<br>\nKullanıcı adı: {\$username}<br>\nParola: {\$password}\n\n"
            ."Alan adınızı ad sunucularımıza veya A kaydını {\$server_ip} adresine yönlendirin.\n\n"
            .'Paket: {$package}, {$amount} {$billing_cycle}, sonraki vade {$next_due_date}.',
    ],
    'service_suspension' => [
        'subject' => 'Hizmet askıya alındı: {$service}',
        'body' => "**{\$service}** {\$domain} hizmetiniz askıya alındı.\n\nNeden: {\$suspension_reason}\n\n{\$payment_instructions}",
    ],
    'service_unsuspension' => [
        'subject' => 'Hizmet yeniden etkin: {$service}',
        'body' => '**{$service}** {$domain} hizmetiniz yeniden etkin.',
    ],

    'cancellation_approved' => [
        'subject' => 'İptal onayı: {$service}',
        'body' => "**{\$service}** {\$domain} için iptal isteğinizi aldık ({\$cancellation_type}).\n\n"
            .'Hizmet {$cancellation_date} tarihinde sona erer ve bunun için artık ödeme bildirimi almazsınız.',
    ],

    'cancellation_rejected' => [
        'subject' => '{$service} için iptal isteğiniz',
        'body' => "**{\$service}** {\$domain} için iptal isteğinizi gerçekleştiremedik, bu yüzden hizmet olduğu gibi kalıyor.\n\n"
            .'Bu konuyu konuşmak isterseniz lütfen bizimle iletişime geçin.',
    ],

    'domain_registration' => [
        'subject' => 'Alan adı kaydı: {$domain}',
        'body' => "**{\$domain}** alan adı {\$registration_years} yıllığına kaydedildi.\n\nAd sunucuları:<br>\n{\$nameservers}",
    ],
    'domain_renewal' => [
        'subject' => 'Alan adı yenilendi: {$domain}',
        'body' => '**{$domain}** alan adı {$registration_years} yıllığına yenilendi. Sonraki vade {$next_due_date}.',
    ],
    'domain_transfer_completed' => [
        'subject' => 'Alan adı transferi tamamlandı: {$domain}',
        'body' => "**{\$domain}** alan adının bize transferi tamamlandı. Bitiş tarihi {\$expiry_date}.\n\nAd sunucuları:<br>\n{\$nameservers}",
    ],
    'domain_renewal_notice' => [
        'subject' => 'Alan adlarınızın yaklaşan yenilemesi',
        'body' => "Aşağıdaki alan adlarının yenileme tarihi yaklaşıyor.\n\n{\$services}\n\n{\$payment_instructions}\n\nÖdemeyi zaten yaptıysanız lütfen bu mesajı dikkate almayın.",
    ],
    'domain_expired' => [
        'subject' => '{$domain} alan adının süresi doldu',
        'body' => "**{\$domain}** alan adının süresi {\$expiry_date} tarihinde doldu. Lütfen en kısa sürede yenileyin, aksi halde herkes tarafından kaydedilebilir hale gelebilir.\n\n{\$payment_instructions}",
    ],

    'usage_warning' => [
        'subject' => '{$domain} paket sınırına yaklaşıyor',
        'body' => "**{\$service}** {\$domain} hosting hesabınız paketinin ({\$package}) sınırlarına yaklaşıyor:\n\n{\$usage}\n\n"
            .'Sınıra ulaştığında web sitesi veya e-posta çalışmayı durdurabilir. Bunu önlemek için yer açabilir veya daha büyük bir pakete geçebilirsiniz: müşteri panelinde hizmetin sayfasındaki **Yükselt / düşür** düğmesine basın ya da bu e-postayı yanıtlayın, size uygun olanı önerelim.',
    ],
    'upgrade_approved' => [
        'subject' => 'Paket değişikliği: {$service}',
        'body' => "**{\$service}** {\$domain} hizmetiniz **{\$new_product}** paketine taşındı ve yeni paket şimdiden geçerli.\n\n"
            .'Yeni fiyatı **{$new_price}** ({$billing_cycle}) olup bir sonraki vade tarihi olan {$effective_date} itibarıyla uygulanır. Mevcut dönemin kalanını ücretlendirirsek bunun için ayrı bir ödeme bildirimi alırsınız.',
    ],
    'upgrade_rejected' => [
        'subject' => '{$service} için paket değişikliği isteğiniz',
        'body' => "**{\$service}** {\$domain} hizmetinizi **{\$new_product}** paketine taşıyamadık, bu yüzden olduğu gibi kalıyor.\n\n"
            .'Bu konuyu konuşmak isterseniz lütfen bizimle iletişime geçin.',
    ],
    'hosting_offer' => [
        'subject' => '{$domain} için hosting',
        'body' => "**{\$domain}** alan adınız bizde, ancak web sitesi ve e-postası başka bir yerde barındırılıyor veya henüz kurulmadı.\n\n"
            ."Bunları alan adınızdaki e-posta adresleriyle birlikte sizin için barındırabilir, her şeyi tek bir yerde ve tek bir yenileme tarihiyle yönetebiliriz.\n\n"
            .'İlgileniyorsanız bu e-postayı yanıtlayın, size uygun paketi önerelim.',
    ],
    'domain_transfer_offer' => [
        'subject' => '{$domain} alan adını {$company_name} bünyesine taşıyın',
        'body' => "**{\$domain}** alan adının başka bir sağlayıcıda kayıtlı olduğunu fark ettik. Diğer hizmetlerinizle birlikte, tek bir yenileme tarihi ve tek bir ödeme bildirimiyle onu da yönetebiliriz.\n\n"
            ."Transfer fiyatı: {\$transfer_price}\n\n"
            .'Transfer etmemizi isterseniz bu e-postayı yanıtlayın, size adımları gönderelim.',
    ],
    'domain_winback' => [
        'subject' => '{$domain} alan adını hâlâ yenileyebilirsiniz',
        'body' => '**{$domain}** alan adının süresi {$expiry_date} tarihinde doldu ve yenilenmedi. Hâlâ {$renewal_price} karşılığında yenileyebilirsiniz. '
            ."Birkaç gün sonra kurtarılması çok daha pahalı hale gelir, daha sonra da herkes kaydedebilir.\n\n"
            .'Korumak isterseniz bu e-postayı yanıtlayın, hemen yenileyelim.',
    ],
    'ssl_validation' => [
        'subject' => 'İşlem gerekli: SSL sertifikası için {$domain} alan adını doğrulayın',
        'body' => "**{\$domain}** için SSL sertifikasının düzenlenmesi amacıyla sertifika otoritesinin alan adının size ait olduğuna dair kanıta ihtiyacı var. Yöntem: {\$validation_method}.\n\n{\$validation}\n\n"
            .'Bunu görebilir, başka bir yöntem seçebilir ve sertifika düzenlendiğinde müşteri panelinde hizmetin sayfasından indirebilirsiniz.',
    ],
    'ssl_issued' => [
        'subject' => '{$domain} için SSL sertifikanız hazır',
        'body' => "**{\$domain}** için SSL sertifikası düzenlendi ve {\$expiry_date} tarihine kadar geçerli.\n\n"
            .'Müşteri panelinde hizmetin sayfasından indirin (sertifika, CA bundle ve özel anahtar) ve web sitenize kurun. Web siteniz bizde barındırılıyorsa bu e-postayı yanıtlayın, sizin için kuralım.',
    ],
    'ticket_opened' => [
        'subject' => 'Destek talebi [{$ticket_number}] {$ticket_subject}',
        'body' => "**{\$ticket_number}** numaralı \"{\$ticket_subject}\" destek talebiniz açıldı:\n\n{\$ticket_message}\n\n"
            .'Talebe hem buradan hem de e-postayla yanıt veriyoruz; talebi müşteri panelinden takip edip yanıtlayabilirsiniz.',
    ],
    'ticket_reply' => [
        'subject' => 'Ynt: [{$ticket_number}] {$ticket_subject}',
        'body' => "**{\$ticket_number}** numaralı \"{\$ticket_subject}\" destek talebinize yeni bir yanıt var:\n\n{\$ticket_message}\n\n"
            .'Müşteri panelinden yanıt verebilirsiniz.',
    ],
    'ticket_closed' => [
        'subject' => 'Kapatıldı: [{$ticket_number}] {$ticket_subject}',
        'body' => "**{\$ticket_number}** numaralı \"{\$ticket_subject}\" destek talebiniz, birkaç gündür sizden haber alamadığımız için kapatıldı.\n\n"
            .'Hâlâ yardıma ihtiyacınız varsa müşteri panelinden yanıt verin, talep yeniden açılır.',
    ],
];
