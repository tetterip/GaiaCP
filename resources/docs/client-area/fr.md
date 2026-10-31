## Connexion

Connectez-vous avec votre adresse email et votre mot de passe. Si vous oubliez le mot de passe, cliquez sur « Mot de passe oublié ? » sur la page de connexion et nous vous envoyons par email un lien pour en choisir un nouveau. Le lien fonctionne une fois.

<!-- if:google_login -->
Si votre adresse email est un compte Google, vous pouvez aussi cliquer sur **Continuer avec Google** sur la page de connexion au lieu de saisir votre mot de passe.
<!-- endif -->

Votre profil (le menu à votre nom, en bas du menu ou en haut à droite) permet de modifier votre adresse email, votre numéro de téléphone, votre langue et votre mot de passe, et d'activer l'authentification à deux facteurs pour plus de sécurité.

<!-- if:notifications -->
La **cloche** en haut de la page vous indique ce qui s'est passé depuis votre dernier passage : un nouvel avis de paiement, un rappel de paiement ou un avis de retard, un paiement que nous avons reçu, un service prêt, suspendu ou terminé, un domaine bientôt renouvelé, un nouveau prix, notre réponse à votre ticket ou à une demande. Un nombre indique combien vous n'en avez pas lu ; cliquez sur l'une d'elles pour ouvrir sa page. Pour les arrêter, désactivez **Notifications** dans votre profil. Nous continuons de vous écrire par email comme avant.

<!-- endif -->
<!-- if:email_history -->
**Historique des emails**, dans le même menu, liste les emails que nous vous avons envoyés, du plus récent au plus ancien : cliquez sur l'un d'eux pour le relire. Les emails contenant un mot de passe ou un lien de connexion sont listés sans leur texte ; utilisez plutôt « Mot de passe oublié ? » sur la page de connexion.

<!-- endif -->
**Appareils et connexions**, dans le même menu, liste les navigateurs connectés à votre compte et vos connexions récentes, mots de passe erronés compris. Cliquez sur **Se déconnecter** à côté d'un appareil que vous n'utilisez plus, ou sur **Déconnecter les autres appareils** pour vous déconnecter partout sauf ici. Si vous voyez une connexion que vous ne reconnaissez pas, changez aussi votre mot de passe.

<!-- if:cookies -->
« Paramètres des cookies », en bas de chaque page, réaffiche le choix des cookies pour que vous puissiez le modifier.
<!-- endif -->

<!-- if:welcome_tour -->
Lors de votre première connexion, une courte visite montre ce que vous pouvez faire ici. Le menu à votre nom → **Visite** la montre à nouveau.
<!-- endif -->

## Accueil

L'Accueil montre ce que vous avez à payer, combien de services sont actifs et ce qui se renouvelle prochainement, avec un lien vers chacun. Nos annonces, comme des travaux prévus sur votre serveur, apparaissent en haut de l'Accueil tant qu'elles sont d'actualité.

## Services et domaines

**Services** liste vos hébergements et autres services avec leur statut, leur prix et leur prochaine échéance. Le menu les répartit entre **Hébergement** et **Autres services**. La page d'un compte d'hébergement indique son cycle de facturation et propose un bouton **Ouvrir le site web**. **Domaines** liste vos domaines avec leur date d'expiration, leurs serveurs de noms et s'ils se renouvellent automatiquement. Quand vous avez des services dans plusieurs statuts, les boutons au-dessus de chaque liste n'affichent que ceux d'un statut (Actif, Suspendu, …), avec leur nombre. Un travail effectué une fois, comme la migration de votre site web, est facturé une fois et apparaît comme **Terminé** après votre paiement.

<!-- if:hosting -->
Sur un compte d'hébergement actif, vous pouvez :

<!-- if:hosting_login -->
- **Se connecter au panneau de contrôle** (cPanel ou Plesk) sans saisir son mot de passe : le bouton l'ouvre dans un nouvel onglet.
- Utiliser les **Raccourcis rapides** (sur cPanel et Plesk) pour ouvrir directement une page du panneau de contrôle, comme les comptes email, le gestionnaire de fichiers ou les bases de données, déjà connecté.
<!-- endif -->
<!-- if:hosting_usage -->
- **Voir l'espace disque et la bande passante utilisés** ce mois-ci, par rapport à ce que permet votre forfait.
<!-- endif -->
<!-- if:hosting_uptime -->
- **Voir la disponibilité du serveur** : la part des 30 derniers jours et des 24 dernières heures pendant laquelle le serveur de votre compte était en ligne. Dans la liste Hébergement, un point devant le domaine indique l'état actuel du serveur : vert quand il fonctionne, rouge quand il ne répond pas, orange pendant une maintenance. Survolez-le pour voir la disponibilité des 30 derniers jours.
<!-- endif -->
<!-- if:vps_power -->
- **Démarrer, éteindre ou redémarrer un VPS** avec les boutons de sa page. Éteindre et Redémarrer arrêtent les programmes qui y tournent : enregistrez d'abord votre travail. Pour utiliser sa console, cliquez sur **Se connecter à Proxmox VE** et connectez-vous avec le nom d'utilisateur et le mot de passe du service (realm « Proxmox VE authentication server ») : vous ne voyez que votre propre VPS.
<!-- endif -->
<!-- if:hosting_password -->
- **Changer le mot de passe** du compte. Utilisez au moins 10 caractères avec majuscules, minuscules et chiffres.
<!-- endif -->
<!-- endif -->

Pour passer un service à un forfait supérieur ou inférieur, cliquez sur **Montée / baisse de gamme** sur sa page et choisissez le nouveau ; chacun affiche son prix pour votre cycle de facturation. Une fois approuvé par nous, le nouveau forfait s'applique immédiatement et le nouveau prix à partir de votre prochaine échéance (nous pouvons aussi envoyer un avis de paiement pour le reste de la période en cours). Nous confirmons par email ; d'ici là, la page affiche la demande et **Retirer la demande de changement de forfait** l'annule.

<!-- if:orders -->
Quand des compléments sont disponibles pour un service (comme des sauvegardes quotidiennes), cliquez sur **Ajouter un complément** sur sa page et choisissez-en un ; chacun affiche son prix pour votre cycle de facturation et ce que vous payez maintenant. Vous ne payez maintenant que les jours jusqu'à la prochaine échéance du service (plus d'éventuels frais d'installation), puis le complément se renouvelle avec le service, sur le même avis de paiement. L'avis de paiement s'ouvre aussitôt ; le complément devient actif une fois payé, et la page le liste sous **Compléments** (avec un lien vers l'avis tant qu'il est en attente).

<!-- endif -->
Pour arrêter un compte d'hébergement ou un autre service, cliquez sur **Demander la résiliation** sur sa page. Indiquez le motif si vous le souhaitez, et choisissez s'il prend fin **à la fin de la période de facturation** (vous continuez à l'utiliser jusqu'à sa prochaine échéance) ou **immédiatement** (le compte et ses fichiers sont supprimés). Si vous avez un domaine du même nom, vous pouvez aussi nous demander de ne pas le renouveler. Nous confirmons par email une fois la résiliation effectuée ; d'ici là, la page affiche la demande et **Retirer la demande de résiliation** l'annule. Pour un domaine, désactivez plutôt le renouvellement auto.

Quand nous changeons le prix d'un service, nous vous prévenons à l'avance par email, et sa page affiche le nouveau prix sous **Prix** avec sa date d'application (par exemple *À partir du 01/12/2026 : 66,00 €*). Les renouvellements à échéance avant cette date gardent le prix actuel, et les avis de paiement déjà émis ne changent pas.

<!-- if:incident_notice -->
Si un serveur qui héberge votre service a un problème ou est en maintenance, un avis en haut de chaque page l'indique et nomme les services concernés. Il disparaît de lui-même une fois que tout fonctionne à nouveau : inutile de nous contacter à ce sujet.

<!-- endif -->
<!-- if:ssl -->
Un **certificat SSL** est commandé pour le domaine indiqué lors de la commande, une fois payé. Sa page indique quoi faire pour prouver que le domaine vous appartient (l'autorité de certification le vérifie avant d'émettre quoi que ce soit), et nous vous l'envoyons aussi par email :

- **Enregistrement DNS (CNAME) :** ajoutez l'enregistrement affiché (nom, type et valeur) à la zone DNS du domaine.
- **Fichier sur le site web :** créez le fichier affiché, avec exactement son contenu, pour qu'il s'ouvre à l'adresse affichée.
- **Email à l'administrateur du domaine :** ouvrez le lien de l'email envoyé à admin@ (ou à l'adresse choisie) et approuvez.

Quand le domaine est un compte d'hébergement que vous avez chez nous, nous le faisons pour vous : sa page indique que c'est déjà en place et, une fois le certificat émis, nous l'installons aussi sur ce compte, vous n'avez donc rien à télécharger ni à installer.

**Modifier la validation** choisit un autre moyen (ou une autre adresse d'approbation) et **Vérifier à nouveau** demande à l'autorité de certification de vérifier maintenant (**Renvoyer l'email d'approbation** quand la validation se fait par email). Une fois le certificat émis, nous vous prévenons par email, et **Télécharger le certificat** fournit un zip avec le certificat, le CA bundle et, si nous l'avons créée, la clé privée, prêts à installer sur le serveur. Un certificat dure au maximum 200 jours : tant que votre service est actif, nous émettons le suivant avant la fin de chacun ; vous validez à nouveau le domaine à chaque fois et téléchargez le nouveau certificat.

<!-- endif -->
<!-- if:domains -->
Sur un domaine actif enregistré chez nous, vous pouvez :

<!-- if:domain_nameservers -->
- **Modifier les serveurs de noms** (2 à 4). La modification part immédiatement au registre ; internet la voit en quelques heures.
<!-- endif -->
<!-- if:domain_epp -->
- **Obtenir le code EPP** nécessaire pour transférer le domaine vers un autre fournisseur. Pour certains domaines, il s'affiche à l'écran ; pour d'autres, il est envoyé à l'adresse email du titulaire du domaine.
<!-- endif -->
<!-- if:domain_auto_renew -->
- **Activer ou désactiver le renouvellement auto.** Désactivé, vous ne recevez plus d'avis de paiement pour le domaine et il prend fin à son échéance.
<!-- endif -->
<!-- if:domain_contacts -->
- **Modifier les contacts** (titulaire, administratif, technique et, pour certains domaines, facturation) tels que le registre les a. **Utiliser les informations de mon compte** remplit un contact à partir de votre profil et **Identique au titulaire** copie celui du titulaire. Certains registres (comme le .gr) ne permettent pas de changer ainsi le nom du titulaire : contactez-nous pour cela.
<!-- endif -->
<!-- if:domain_child_nameservers -->
- **Enregistrer des serveurs de noms privés** nommés d'après votre domaine (comme ns1.votredomaine.gr) avec leur adresse IP, modifier leur IP ou les supprimer.
<!-- endif -->
<!-- if:domain_lock -->
- **Verrouiller ou déverrouiller le domaine** (verrou registrar). Un domaine verrouillé ne peut pas être transféré vers un autre fournisseur ; ne le déverrouillez que juste avant un transfert. Tous les registres n'ont pas de verrou.
<!-- endif -->
<!-- endif -->

## Avis de paiement et paiements

Quand un service arrive à échéance, nous vous envoyons par email un **avis de paiement**, avec les instructions de paiement. Il s'agit d'un avis de paiement, sans valeur fiscale. **Avis de paiement** les liste tous : ouvrez-en un pour voir ses lignes, l'imprimer ou l'enregistrer en PDF. Les boutons au-dessus de la liste n'affichent que les impayés, les payés ou ceux en retard. **Paiements** liste les paiements que nous avons enregistrés. Après votre paiement, l'avis apparaît comme payé dès que nous enregistrons votre paiement.

<!-- if:invoices -->
Quand nous émettons des factures, un avis de paiement payé reçoit aussi une **facture** : nous vous l'envoyons par email en PDF, l'avis payé a un bouton pour la télécharger, et **Factures** (dans Facturation) les liste toutes. Un **avoir** à cet endroit, avec un total négatif, annule une facture, par exemple quand un paiement est remboursé.
<!-- endif -->

<!-- if:pay_card -->
Pour payer par carte, ouvrez un avis de paiement impayé et cliquez sur **Payer par carte**. Vous payez sur la page de paiement sécurisée de Stripe (nous ne voyons jamais les données de votre carte), et l'avis apparaît comme payé dès que le paiement est passé.
<!-- endif -->

<!-- if:pay_paypal -->
Pour payer avec PayPal, ouvrez un avis de paiement impayé et cliquez sur **Payer avec PayPal**. Vous approuvez le paiement sur la page de PayPal, avec votre solde PayPal ou une carte, et l'avis apparaît comme payé dès que PayPal le confirme.
<!-- endif -->

<!-- if:credit -->
## Devis

Quand nous vous envoyons un devis, vous recevez un email avec un lien vers celui-ci, et **Devis** (sous Facturation) liste ceux que nous vous avons envoyés, avec leur premier paiement et leur durée de validité. Cliquez sur l'un d'eux pour l'ouvrir : vous pouvez l'imprimer depuis là. **Accepter** passe la commande aux prix du devis (avec nos conditions d'utilisation, quand nous vous demandons de les accepter), et son avis de paiement vous est envoyé par email avec les instructions de paiement. **Refuser** nous indique que vous n'en voulez pas ; nous dire pourquoi nous aide. Une fois sa date passée, un devis ne peut plus être accepté : répondez à notre email et nous vous en enverrons un nouveau.

## Crédit

**Crédit** affiche votre solde créditeur et son historique. Pour ajouter des fonds, cliquez sur **Ajouter des fonds** et saisissez un montant : nous créons un avis de paiement de ce montant, que vous réglez comme les autres. Une fois payé, le montant est ajouté à votre crédit. Vous pouvez ajouter des fonds dès que vous avez un service actif.

Le crédit règle vos avis de paiement ligne par ligne : une ligne (le renouvellement d'un service) n'est réglée que si votre crédit la couvre entièrement, et ce qu'il ne couvre pas reste sur l'avis à régler normalement.
<!-- if:credit_auto -->
Nous utilisons automatiquement votre crédit sur les nouveaux avis de paiement, et sur les impayés quand vous ajoutez des fonds.
<!-- endif -->
<!-- if:credit_manual -->
Pour l'utiliser, ouvrez un avis de paiement impayé et cliquez sur **Payer avec le crédit**.
<!-- endif -->
<!-- endif -->

<!-- if:orders -->
## Commander

**Recherche de domaine** (dans le menu, après Domaines) vous trouve un nom : saisissez-le (maboutique, ou maboutique.gr) et vous le voyez avec chaque extension que nous enregistrons, libre ou pris, et son prix. **Ajouter au panier** met un nom libre dans le panier (**Avec SSL** ajoute aussi un certificat pour lui, quand nous en proposons un) ; pour un nom qui vous appartient mais se trouve ailleurs, **À moi : le transférer** ouvre la commande du transfert. Il peut d'abord vous être demandé de confirmer que vous n'êtes pas un robot. « Probablement disponible » signifie que le registre n'a pas pu être interrogé à ce moment ; nous le vérifions à nouveau lors de l'enregistrement. Quand nous le proposons, **Suggérer d'autres noms** sous les résultats liste des noms libres proches du vôtre, suggérés par l'IA, avec la même extension.

<!-- if:domain_prices -->
**Voir les prix de toutes les extensions**, sous le champ de recherche, liste chaque extension que nous vendons avec son prix d'enregistrement, de transfert et de renouvellement.
<!-- endif -->

**Commande** montre ce que vous pouvez commander ; **Commander un nouveau service**, au-dessus de vos Services, l'ouvre aussi (**Commander un domaine**, au-dessus de vos Domaines, ouvre la recherche de domaine). Quand nous vendons plusieurs types de services, choisissez d'abord le type (comme hébergement mutualisé ou VPS, ou noms de domaine), puis une offre : **Comparer les offres**, en dessous, montre côte à côte ce que chacune comprend, avec son prix pour chaque période de facturation, et ses boutons **Choisir** sélectionnent aussi une offre. Les badges à côté du nom d'une offre (comme Populaire ou Nouveau) signalent ce que nous recommandons ou ce qui est nouveau. Choisissez un service et sa période de facturation, et les options qu'il propose (comme un emplacement ou des adresses IP supplémentaires) ; chaque option indique ce qu'elle ajoute au prix. Ses compléments (comme des sauvegardes quotidiennes) peuvent être cochés en dessous ; ils figurent sur le même avis de paiement et se renouvellent avec lui. Pour un hébergement, indiquez si vous avez déjà le domaine, voulez en enregistrer un nouveau ou transférer un domaine que vous avez ailleurs (il vous faut son code EPP). Vous pouvez aussi commander un domaine seul, avec un certificat SSL pour lui quand nous en proposons un (cochez-le sous le domaine). Dès que vous saisissez un domaine à enregistrer ou transférer, vous voyez à côté s'il est libre ; un domaine pris ne peut qu'être transféré, et un domaine pas encore enregistré ne peut qu'être enregistré.

Cliquez sur **Ajouter au panier** et le **Panier** s'ouvre (l'icône du panier en haut de chaque page l'ouvre aussi, avec le nombre d'éléments qu'il contient). Cliquez sur **Commander autre chose** pour en ajouter, comme deux domaines et trois hébergements, ou sur la corbeille à côté d'un élément pour le retirer. Tout le contenu du panier devient une seule commande avec un seul avis de paiement.

Si vous avez un **code promo**, saisissez-le dans le panier : vous voyez ce qu'il déduit avant de passer la commande (un lien contenant un code le remplit pour vous). Un code promo peut réduire seulement le premier paiement ou aussi chaque renouvellement, et il ne réduit jamais des frais d'installation.

Ce qui est **en promotion** affiche le badge Promotion et son prix normal barré. Le prix promotionnel s'applique à la première période de ce que vous commandez pendant la promotion ; les renouvellements sont au prix normal. Un code promo peut ne pas réduire davantage un prix promotionnel.

<!-- if:terms -->
Avant de la passer, lisez nos conditions d'utilisation (le lien à côté de la case) et cochez **J'accepte les conditions d'utilisation**.
<!-- endif -->

Quand vous passez la commande, nous vous envoyons son avis de paiement par email. Nous mettons les services en service dès que la commande est prête, et vérifions et enregistrons le domaine à ce moment-là.
<!-- endif -->

## Vos coordonnées

Votre nom, votre société, vos informations de TVA et votre adresse figurent sur vos avis de paiement et comme titulaire de vos domaines. Vous pouvez demander à les modifier depuis votre profil ; nous examinons la modification et l'approuvons. L'email et le téléphone changent immédiatement, de même que les informations supplémentaires que nous pouvons demander sous Plus de détails.

Nos actualités et offres se terminent par un lien pour les arrêter ; les emails concernant vos services, avis de paiement et rappels continuent d'arriver.

<!-- if:knowledge_base -->
## Base de connaissances

Dans Support → Base de connaissances, vous trouvez les réponses aux questions fréquentes, regroupées par sujet. Saisissez quelques mots dans la recherche pour trouver un article ; elle les trouve avec ou sans accents. À la fin de chaque article, dites-nous s'il vous a aidé.
<!-- if:tickets -->
Pendant que vous saisissez l'objet d'un nouveau ticket, les articles qui y répondent peut-être déjà s'affichent en dessous.
<!-- endif -->

<!-- endif -->
<!-- if:tickets -->
## Tickets de support

Dans Support → Tickets, **Ouvrir un ticket** vous permet de nous demander n'importe quoi : choisissez le département et le service concerné, rédigez votre question et joignez des fichiers comme des captures d'écran. Nous répondons sur le ticket et par email ; cliquez sur **Répondre** pour nous écrire en retour. Fermez-le quand vous n'avez plus besoin de rien. Un ticket fermé se rouvre si vous répondez, et un ticket auquel nous avons répondu se ferme de lui-même si nous n'avons pas de nouvelles de votre part pendant quelques jours.
<!-- endif -->
<!-- if:ticket_mail -->
Vous pouvez aussi répondre à nos emails de ticket depuis votre propre messagerie : votre réponse est ajoutée au ticket, avec ses pièces jointes. Un email envoyé à notre adresse de support ouvre un nouveau ticket.
<!-- endif -->

<!-- if:announcements -->
## Annonces

Dans Support → Annonces, nous publions les actualités de nos services : nouveaux services, changements de prix et maintenances prévues, des plus récentes aux plus anciennes.
<!-- endif -->
<!-- if:server_status -->
## État des serveurs

Support → État des serveurs indique si nos serveurs fonctionnent en ce moment, avec les carrés des 30 derniers jours et des 24 dernières heures. Quand l'un d'eux a un problème, il apparaît en haut avec ce que nous en savons.
<!-- endif -->
<!-- if:contact_page -->
## Nous contacter

Support → Nous contacter indique notre adresse, notre téléphone, notre email et nos horaires d'ouverture, tels que nous les avons renseignés. Cliquez sur un numéro de téléphone ou une adresse email pour appeler ou écrire, et sur **Ouvrir dans les cartes** pour nous trouver.
<!-- endif -->

## Des questions ?

Écrivez-nous à {company_email}.
