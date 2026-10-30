<?php

// Built-in French text of the client emails (App\Enums\MailTemplate), used for clients whose email language is
// French until the admin saves a French text of their own. Same placeholders as the English text. Payment notices
// are proformas: call them avis de paiement, never facture.

$notice = "{\$invoice_items}\n\n{\$invoice_tax}\n\nTotal : **{\$invoice_total}**, à régler avant le {\$invoice_due_date}.\n\n{\$payment_instructions}";

return [
    'greeting' => 'Bonjour {$client_first_name},',
    'closing' => "Cordialement,<br>\n{\$signature}",

    'client_signup' => [
        'subject' => 'Bienvenue chez {$company_name}',
        'body' => "Merci d'avoir choisi {\$company_name}. Votre compte a été créé avec l'adresse email {\$client_email}.",
    ],
    'client_area_invite' => [
        'subject' => 'Votre espace client chez {$company_name}',
        'body' => "Vous pouvez désormais consulter vos services, domaines et avis de paiement dans l'espace client de {\$company_name}.\n\n"
            ."[Choisissez votre mot de passe]({\$set_password_url})\n\nLe lien fonctionne une fois, pendant 48 heures. Votre nom d'utilisateur est votre adresse email, {\$client_email}. "
            .'Ensuite, connectez-vous sur {$client_area_url}, où « Mot de passe oublié ? » vous envoie un nouveau lien.',
    ],
    'client_area_login' => [
        'subject' => "Vos identifiants de l'espace client de {\$company_name}",
        'body' => "Bienvenue chez {\$company_name}. Vous pouvez désormais consulter vos services, domaines et avis de paiement dans notre espace client.\n\n"
            ."Adresse : {\$client_area_url}\n\nNom d'utilisateur : {\$client_email}\n\nMot de passe : {\$password}\n\n"
            ."Veuillez changer le mot de passe depuis votre profil après votre connexion. Si vous l'oubliez, « Mot de passe oublié ? » sur la page de connexion vous envoie un nouveau lien.",
    ],
    'email_verification' => [
        'subject' => 'Confirmez votre adresse email chez {$company_name}',
        'body' => "Merci de vous être inscrit chez {\$company_name}. Veuillez confirmer votre adresse email pour terminer :\n\n"
            ."[Confirmer mon adresse email]({\$verification_url})\n\n"
            .'Le lien fonctionne pendant 60 minutes ; ensuite, connectez-vous et demandez-en un nouveau. Si vous ne vous êtes pas inscrit, veuillez ignorer cet email.',
    ],
    'order_confirmation' => [
        'subject' => 'Confirmation de la commande n° {$order_number}',
        'body' => "Nous avons bien reçu votre commande n° {\$order_number} du {\$order_date}.\n\n{\$order_items}\n\nTotal : **{\$order_total}**\n\n{\$payment_instructions}",
    ],

    'renewal_reminder' => [
        'subject' => 'Renouvellement prochain de vos services',
        'body' => "Les services suivants arrivent bientôt à renouvellement.\n\n{\$services}\n\n{\$payment_instructions}\n\nSi vous avez déjà payé, veuillez ignorer ce message.",
    ],
    'overdue_notice' => [
        'subject' => 'Retard de paiement : services en attente de règlement',
        'body' => "Les services suivants ont dépassé leur échéance. Veuillez procéder au paiement afin d'éviter leur suspension.\n\n{\$services}\n\n{\$payment_instructions}\n\nSi vous avez déjà payé, veuillez ignorer ce message.",
    ],
    'invoice_created' => [
        'subject' => 'Avis de paiement {$invoice_number}',
        'body' => "Voici l'avis de paiement {\$invoice_number} du {\$invoice_date} pour vos services. Il s'agit d'un avis de paiement, sans valeur fiscale.\n\n{$notice}",
    ],
    'invoice_payment_reminder' => [
        'subject' => 'Rappel : l\'avis de paiement {$invoice_number} est à régler avant le {$invoice_due_date}',
        'body' => "Nous vous rappelons que l'avis de paiement {\$invoice_number} est à régler avant le {\$invoice_due_date}.\n\n{$notice}",
    ],
    'invoice_overdue_first' => [
        'subject' => 'L\'avis de paiement {$invoice_number} est en retard',
        'body' => "L'avis de paiement {\$invoice_number} était à régler avant le {\$invoice_due_date} et reste impayé. Jours de retard : {\$days_overdue}.\n\n{$notice}",
    ],
    'invoice_overdue_second' => [
        'subject' => 'Second rappel : l\'avis de paiement {$invoice_number} est en retard',
        'body' => "Nous vous rappelons une seconde fois que l'avis de paiement {\$invoice_number}, à régler avant le {\$invoice_due_date}, reste impayé. Veuillez le régler afin d'éviter la suspension de vos services.\n\n{$notice}",
    ],
    'invoice_modified' => [
        'subject' => 'Mise à jour de l\'avis de paiement {$invoice_number}',
        'body' => "L'avis de paiement {\$invoice_number} a été mis à jour.\n\n{$notice}",
    ],
    'tax_invoice_issued' => [
        'subject' => 'Facture {$tax_invoice_number}',
        'body' => "Merci pour votre paiement. Vous trouverez ci-joint la facture **{\$tax_invoice_number}** du {\$tax_invoice_date}, pour l'avis de paiement {\$invoice_number}.\n\n{\$invoice_items}\n\n{\$invoice_tax}\n\nTotal payé : **{\$invoice_total}**",
    ],
    'price_change' => [
        'subject' => 'Changement de prix de vos services à partir du {$effective_date}',
        'body' => "Nous vous informons que le prix des services suivants change pour les renouvellements à échéance à partir du **{\$effective_date}**.\n\n"
            ."{\$price_changes}\n\nD'ici là, rien ne change, et les avis de paiement déjà émis restent tels quels.\n\n"
            .'Pour toute question, ou si vous souhaitez parler de votre offre, répondez simplement à cet email.',
    ],

    'quote' => [
        'subject' => 'Devis {$quote_number} de {$company_name}',
        'body' => "Merci pour votre intérêt. Voici notre devis **{\$quote_number}**.\n\n{\$quote_intro}\n\n{\$quote_items}\n\n"
            ."Premier paiement : **{\$quote_total}**. Le devis est valable jusqu'au {\$quote_valid_until}.\n\n"
            .'[Voir et accepter le devis]({$quote_url})'."\n\n".'Pour toute question, répondez simplement à cet email.',
    ],

    'hosting_welcome' => [
        'subject' => 'Votre compte d\'hébergement pour {$domain} est prêt',
        'body' => "Votre compte d'hébergement pour **{\$domain}** est prêt.\n\n"
            ."Panneau de contrôle : {\$control_panel_url}<br>\nNom d'utilisateur : {\$username}<br>\nMot de passe : {\$password}\n\n"
            ."Faites pointer votre domaine vers nos serveurs de noms, ou son enregistrement A vers {\$server_ip}.\n\n"
            .'Forfait : {$package}, {$amount} ({$billing_cycle}), prochaine échéance le {$next_due_date}.',
    ],
    'service_suspension' => [
        'subject' => 'Service suspendu : {$service}',
        'body' => "Votre service **{\$service}** {\$domain} a été suspendu.\n\nMotif : {\$suspension_reason}\n\n{\$payment_instructions}",
    ],
    'service_unsuspension' => [
        'subject' => 'Service réactivé : {$service}',
        'body' => 'Votre service **{$service}** {$domain} est de nouveau actif.',
    ],

    'cancellation_approved' => [
        'subject' => 'Résiliation confirmée : {$service}',
        'body' => "Nous avons bien reçu votre demande de résiliation pour **{\$service}** {\$domain} ({\$cancellation_type}).\n\n"
            .'Le service prend fin le {$cancellation_date} et vous ne recevrez plus d\'avis de paiement pour celui-ci.',
    ],

    'cancellation_rejected' => [
        'subject' => 'Votre demande de résiliation pour {$service}',
        'body' => "Nous n'avons pas pu donner suite à votre demande de résiliation pour **{\$service}** {\$domain}, le service reste donc tel quel.\n\n"
            .'N\'hésitez pas à nous contacter si vous souhaitez en parler.',
    ],

    'domain_registration' => [
        'subject' => 'Domaine enregistré : {$domain}',
        'body' => "Le domaine **{\$domain}** a été enregistré pour {\$registration_years} an(s).\n\nServeurs de noms :<br>\n{\$nameservers}",
    ],
    'domain_renewal' => [
        'subject' => 'Domaine renouvelé : {$domain}',
        'body' => 'Le domaine **{$domain}** a été renouvelé pour {$registration_years} an(s). Prochaine échéance le {$next_due_date}.',
    ],
    'domain_transfer_completed' => [
        'subject' => 'Transfert de domaine terminé : {$domain}',
        'body' => "Le transfert de **{\$domain}** chez nous est terminé. Il expire le {\$expiry_date}.\n\nServeurs de noms :<br>\n{\$nameservers}",
    ],
    'domain_renewal_notice' => [
        'subject' => 'Renouvellement prochain de vos domaines',
        'body' => "Les domaines suivants arrivent bientôt à renouvellement.\n\n{\$services}\n\n{\$payment_instructions}\n\nSi vous avez déjà payé, veuillez ignorer ce message.",
    ],
    'domain_expired' => [
        'subject' => 'Domaine expiré : {$domain}',
        'body' => "Le domaine **{\$domain}** a expiré le {\$expiry_date}. Renouvelez-le rapidement, sinon n'importe qui pourrait l'enregistrer.\n\n{\$payment_instructions}",
    ],

    'usage_warning' => [
        'subject' => '{$domain} approche de la limite de son forfait',
        'body' => "Votre compte d'hébergement **{\$service}** {\$domain} approche des limites de son forfait ({\$package}) :\n\n{\$usage}\n\n"
            ."Une fois la limite atteinte, le site web ou les emails peuvent cesser de fonctionner. Pour l'éviter, vous pouvez libérer de l'espace ou passer à un forfait supérieur : cliquez sur **Montée / baisse de gamme** sur la page du service dans l'espace client, ou répondez à cet email et nous vous proposerons celui qui vous convient.",
    ],
    'upgrade_approved' => [
        'subject' => 'Forfait changé : {$service}',
        'body' => "Votre service **{\$service}** {\$domain} est passé à **{\$new_product}**, et son nouveau forfait s'applique déjà.\n\n"
            .'Son nouveau prix est de **{$new_price}** ({$billing_cycle}), facturé à partir de sa prochaine échéance, le {$effective_date}. Si nous facturons le reste de la période en cours, vous recevrez un avis de paiement distinct.',
    ],
    'upgrade_rejected' => [
        'subject' => 'Votre demande de changement de forfait pour {$service}',
        'body' => "Nous n'avons pas pu passer votre service **{\$service}** {\$domain} à **{\$new_product}**, il reste donc tel quel.\n\n"
            .'N\'hésitez pas à nous contacter si vous souhaitez en parler.',
    ],
    'hosting_offer' => [
        'subject' => 'Hébergement pour {$domain}',
        'body' => "Votre domaine **{\$domain}** est chez nous, mais son site web et ses emails sont hébergés ailleurs, ou pas encore configurés.\n\n"
            ."Nous pouvons les héberger pour vous, avec des adresses email sur votre domaine, et tout gérer au même endroit avec une seule date de renouvellement.\n\n"
            .'Si cela vous intéresse, répondez à cet email et nous vous proposerons l\'offre qui vous convient.',
    ],
    'domain_transfer_offer' => [
        'subject' => 'Transférez {$domain} chez {$company_name}',
        'body' => "Nous avons remarqué que **{\$domain}** est enregistré chez un autre fournisseur. Nous pouvons le gérer avec vos autres services, avec une seule date de renouvellement et un seul avis de paiement.\n\n"
            ."Prix du transfert : {\$transfer_price}\n\n"
            .'Si vous souhaitez que nous le transférions, répondez à cet email et nous vous enverrons la marche à suivre.',
    ],
    'domain_winback' => [
        'subject' => 'Vous pouvez encore renouveler {$domain}',
        'body' => "Le domaine **{\$domain}** a expiré le {\$expiry_date} et n'a pas été renouvelé. Vous pouvez encore le renouveler pour {\$renewal_price}. "
            ."Dans quelques jours, sa récupération coûtera beaucoup plus cher, et plus tard n'importe qui pourra l'enregistrer.\n\n"
            .'Si vous souhaitez le conserver, répondez à cet email et nous le renouvellerons immédiatement.',
    ],
    'ssl_validation' => [
        'subject' => 'Action requise : validez {$domain} pour son certificat SSL',
        'body' => "Pour émettre le certificat SSL de **{\$domain}**, l'autorité de certification a besoin d'une preuve que le domaine vous appartient. Méthode : {\$validation_method}.\n\n{\$validation}\n\n"
            ."Vous pouvez consulter ces informations, choisir une autre méthode et télécharger le certificat une fois émis sur la page du service dans l'espace client.",
    ],
    'ssl_issued' => [
        'subject' => 'Votre certificat SSL pour {$domain} est prêt',
        'body' => "Le certificat SSL de **{\$domain}** a été émis et est valable jusqu'au {\$expiry_date}.\n\n"
            ."Téléchargez-le depuis la page du service dans l'espace client (certificat, CA bundle et clé privée) et installez-le sur votre site web. Si votre site est hébergé chez nous, répondez à cet email et nous l'installerons pour vous.",
    ],
    'ticket_opened' => [
        'subject' => 'Ticket de support [{$ticket_number}] {$ticket_subject}',
        'body' => "Votre ticket de support **{\$ticket_number}**, « {\$ticket_subject} », est ouvert :\n\n{\$ticket_message}\n\n"
            ."Nous répondons sur le ticket et par email, et vous pouvez le suivre et y répondre depuis l'espace client.",
    ],
    'ticket_reply' => [
        'subject' => 'Re : [{$ticket_number}] {$ticket_subject}',
        'body' => "Il y a une nouvelle réponse à votre ticket de support **{\$ticket_number}**, « {\$ticket_subject} » :\n\n{\$ticket_message}\n\n"
            ."Vous pouvez répondre depuis l'espace client.",
    ],
    'ticket_closed' => [
        'subject' => 'Fermé : [{$ticket_number}] {$ticket_subject}',
        'body' => "Votre ticket de support **{\$ticket_number}**, « {\$ticket_subject} », a été fermé, car nous n'avons pas eu de nouvelles de votre part depuis quelques jours.\n\n"
            ."Si vous avez encore besoin d'aide, répondez-y depuis l'espace client et il sera rouvert.",
    ],
];
