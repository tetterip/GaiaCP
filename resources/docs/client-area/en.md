## Logging in

Log in with your email address and your password. If you forget the password, press "Forgot password?" on the login page and we email you a link to choose a new one. The link works once.

<!-- if:google_login -->
If your email address is a Google account, you can also press **Continue with Google** on the login page instead of typing your password.
<!-- endif -->

Your profile (the menu with your name, at the bottom of the menu or at the top right) changes your email address, phone number, language and password, and can turn on two-factor authentication for extra safety.

<!-- if:notifications -->
The **bell** at the top of the page tells you what happened since you last looked: a new payment notice, a payment reminder or overdue notice, a payment we received, a service that is ready, suspended or ended, a domain about to renew, a new price, our answer to your ticket or to a request. A number on it shows how many you haven't read; press one to open its page. To stop them, turn off **Notifications** in your profile. We still email you as before.

<!-- endif -->
<!-- if:email_history -->
**Email history**, in the same menu, lists the emails we sent you, newest first: press one to read it again. Emails with a password or a sign-in link are listed without their text; use "Forgot password?" on the login page instead.

<!-- endif -->
**Devices and sign-ins**, in the same menu, lists the browsers signed in to your account and your recent sign-ins, wrong passwords included. Press **Log out** next to a device you don't use any more, or **Log out other devices** to sign out everywhere but here. If you see a sign-in you don't recognise, change your password too.

<!-- if:cookies -->
"Cookie settings" at the foot of every page shows the cookie choice again, so you can change it.
<!-- endif -->

## Home

Home shows what you have to pay, how many services are active and what renews next, with a link to each. Our announcements, such as planned work on your server, appear at the top of Home while they apply.

## Services and domains

**Services** lists your hosting and other services with their status, price and next due date. The menu splits them into **Hosting** and **Other services**. A hosting account's page shows its billing cycle and has an **Open website** button. **Domains** lists your domains with their expiry date, nameservers and whether they renew automatically. When you have services in more than one status, the buttons above each list show only those in one status (Active, Suspended, …), with how many there are. Work done once, such as moving your website, is billed once and shows as **Completed** after you pay it.

<!-- if:hosting -->
On an active hosting account you can:

<!-- if:hosting_login -->
- **Log in to control panel** (cPanel or Plesk) without typing its password: the button opens it in a new tab.
- Use the **Quick shortcuts** (on cPanel and Plesk) to open a page of the control panel directly, such as email accounts, the file manager or databases, already signed in.
<!-- endif -->
<!-- if:hosting_usage -->
- **See how much disk space and bandwidth it uses** this month, against what your package allows.
<!-- endif -->
<!-- if:hosting_uptime -->
- **See the server's uptime**: how much of the last 30 days and 24 hours the server your account is on was up. In the Hosting list, a dot before the domain shows the server's state now: green while it works, red while it doesn't respond, orange during maintenance. Point at it to see the last 30 days' uptime.
<!-- endif -->
<!-- if:vps_power -->
- **Start, shut down or restart a VPS** with the buttons on its page. Shut down and Restart stop the programs running on it, so save your work first. To use its console, press **Log in to Proxmox VE** and sign in with the username and password of the service (realm "Proxmox VE authentication server"): you see only your own VPS.
<!-- endif -->
<!-- if:hosting_password -->
- **Change the password** of the account. Use at least 10 characters with capital and small letters and numbers.
<!-- endif -->
<!-- endif -->

To move a service to a bigger or smaller package, press **Upgrade / downgrade** on its page and pick the new one; each shows its price for your billing cycle. Once we approve it, the new package applies at once and the new price from your next due date (we may also send a payment notice for the rest of the current period). We confirm by email; until then the page shows the request and **Withdraw package change request** takes it back.

<!-- if:orders -->
When add-ons are available for a service (such as daily backups), press **Add an add-on** on its page and pick one; each shows its price for your billing cycle and what you pay now. You pay now only for the days until the service's next due date (plus any setup fee), and from then on the add-on renews together with the service, on the same payment notice. The payment notice opens right away; the add-on becomes active once it is paid, and the page lists it under **Add-ons** (with a link to the notice while it waits).

<!-- endif -->
To stop a hosting account or another service, press **Request cancellation** on its page. Say why if you like, and choose whether it ends **at the end of the billing period** (you keep using it until its next due date) or **immediately** (the account and its files are deleted). If you have a domain with the same name, you can also ask us not to renew it. We confirm by email once we have carried it out; until then the page shows the request and **Withdraw cancellation request** takes it back. For a domain, turn auto renew off instead.

When we change the price of a service, we email you in advance, and its page shows the new price under **Price** with the date it applies from (for example *From 01/12/2026: €66.00*). Renewals due before that date keep the current price, and payment notices already issued do not change.

<!-- if:incident_notice -->
If a server your hosting is on has a problem or is in maintenance, a notice at the top of every page says so and names the services affected. It goes away by itself once everything works again, so there is no need to contact us about it.

<!-- endif -->
<!-- if:ssl -->
An **SSL certificate** is ordered for the domain you give when you order it, once it is paid. Its page shows what to do to prove the domain is yours (the certificate authority checks this before it issues anything), and we also email it to you:

- **DNS record (CNAME):** add the record shown (name, type and value) to the domain's DNS zone.
- **File on the website:** create the file shown, with exactly its contents, so that it opens at the address shown.
- **Email to the domain's administrator:** open the link in the email sent to admin@ (or the address chosen) and approve.

**Change validation** picks another way (or another approval address) and **Check again** asks the certificate authority to look now (**Send the approval email again** when it is by email). Once the certificate is issued we email you, and **Download certificate** gives a zip with the certificate, the CA bundle and, when we made it, the private key, ready to install on the server. A certificate lasts at most 200 days, so while your service runs we issue the next one before each ends: you validate the domain again each time and download the new certificate.

<!-- endif -->
<!-- if:domains -->
On an active domain registered through us you can:

<!-- if:domain_nameservers -->
- **Change the nameservers** (2 to 4). The change goes to the registry at once; the internet sees it within a few hours.
<!-- endif -->
<!-- if:domain_epp -->
- **Get the EPP code** you need to move the domain to another provider. For some domains it is shown on screen; for others it is emailed to the domain owner's email address.
<!-- endif -->
<!-- if:domain_auto_renew -->
- **Turn auto renew off or on.** With it off, you get no more payment notices for the domain and it ends on its due date.
<!-- endif -->
<!-- if:domain_contacts -->
- **Change the contact details** (owner, administrative, technical and, for some domains, billing contact) as the registry has them. **Use my account details** fills a contact in from your profile and **Same as the owner** copies the owner's. Some registries (such as .gr) don't allow changing the owner's name this way: contact us for that.
<!-- endif -->
<!-- if:domain_child_nameservers -->
- **Register private nameservers** named after your domain (such as ns1.yourdomain.gr) with their IP address, change their IP or delete them.
<!-- endif -->
<!-- if:domain_lock -->
- **Lock or unlock the domain** (registrar lock). A locked domain can't be moved to another provider; unlock it only just before a transfer. Not every registry has a lock.
<!-- endif -->
<!-- endif -->

## Payment notices and payments

When a service is due we send you a **payment notice** by email, with how to pay. It is a payment notice, not a tax invoice. **Payment notices** lists them all: open one to see its lines, print it or save it as a PDF. The buttons above the list show only the unpaid, paid or overdue ones. **Payments** lists the payments we have recorded. After you pay, the notice shows as paid once we record your payment.

<!-- if:pay_online -->
To pay by card, open an unpaid payment notice and press **Pay by card**. You pay on Stripe's secure payment page (we never see your card details), and the notice shows as paid as soon as the payment goes through.
<!-- endif -->

<!-- if:credit -->
## Quotes

When we send you a quote, you get an email with a link to it, and **Quotes** (under Billing) lists the ones we sent you, with their first payment and how long they are valid. Press one to open it: you can print it there. **Accept** places the order at the quote's prices (with our Terms of Service, when we ask you to accept them), and its payment notice is emailed to you with how to pay. **Decline** tells us you don't want it; saying why helps us. Once its date has passed a quote can't be accepted: reply to our email and we will send you a new one.

## Credit

**Credit** shows your credit balance and its history. To add funds, press **Add funds** and enter an amount: we create a payment notice for it, which you pay like any other. Once it is paid, the amount is added to your credit. You can add funds once you have an active service.

Credit pays your payment notices one line at a time: a line (a service's renewal) is paid only when your credit covers all of it, and anything it doesn't cover stays on the notice to pay as usual.
<!-- if:credit_auto -->
We use your credit automatically on new payment notices, and on unpaid ones when you add funds.
<!-- endif -->
<!-- if:credit_manual -->
To use it, open an unpaid payment notice and press **Pay with credit**.
<!-- endif -->
<!-- endif -->

<!-- if:orders -->
## Ordering

**Domain search** (in the menu, after Domains) finds a name for you: type it (myshop, or myshop.gr) and you see it with each ending we register, whether it is free or taken, and its price. **Add to cart** puts a free one in the cart (**With SSL** adds a certificate for it too, when we offer one); for one that is yours but somewhere else, **Mine: transfer it** opens the order for the transfer. You may be asked first to confirm that you are not a robot. “Probably available” means the registry could not be asked right then; we check it again when we register it. When we offer it, **Suggest other names** below the results lists free names close to yours, suggested by AI, with the same ending.

<!-- if:domain_prices -->
**See the prices of every domain ending** under the search box lists each ending we sell with its price to register, transfer and renew.
<!-- endif -->

**Order** shows what you can order; **Order a new service** above your Services, open it too (**Order a domain** above your Domains opens the domain search). When we sell several kinds of service, first choose the kind (such as shared hosting or a VPS, or domain names), then a package: **Compare packages** below them shows what each one includes side by side, with its price for each billing period, and its **Choose** buttons pick a package too. Choose a service and its billing period, and any options it offers (such as a location or extra IP addresses); each option shows what it adds to the price. Add-ons for it (such as daily backups) can be ticked below; they are on the same payment notice and renew together with it. For hosting, say whether you already have the domain, want to register a new one or move one you have elsewhere (you need its EPP code). You can also order a domain on its own, with an SSL certificate for it when we offer one (tick it below the domain). Once you type a domain to register or transfer, you see by it whether it is free; a taken domain can only be transferred, and one not registered yet only registered.

Press **Add to cart** and the **Cart** opens (the cart icon at the top of every page opens it too, with how many items are in it). Press **Order something else** to add more, such as two domains and three hosting plans, or the bin next to an item to take it out. Everything in the cart becomes one order with one payment notice.

<!-- if:terms -->
Before you place it, read our Terms of Service (the link next to the box) and tick **I accept the Terms of Service**.
<!-- endif -->

When you place the order, we email you its payment notice. We set the services up as soon as the order is ready, and check and register the domain then.
<!-- endif -->

## Your details

Your name, company, VAT details and address appear on your payment notices and as the owner of your domains. You can ask to change them from your profile; we look at the change and approve it. Email and phone change at once, and so do the extra details we may ask for under More details.

Our news and offers end with a link to stop them; emails about your services, payment notices and reminders keep coming.

<!-- if:knowledge_base -->
## Knowledge base

Under Support → Knowledge base you find answers to common questions, grouped by subject. Type a few words in the search to find an article; it finds them with or without accents. At the end of each article, tell us whether it helped.
<!-- if:tickets -->
While you write the subject of a new ticket, articles that may already answer it show under it.
<!-- endif -->

<!-- endif -->
<!-- if:tickets -->
## Support tickets

Under Support → Tickets, **Open a ticket** to ask us anything: choose the department and the service it is about, write your question and attach files such as screenshots. We answer on the ticket and by email; press **Reply** on it to write back. Close it when you need nothing more. A closed ticket opens again if you reply, and one we answered closes by itself when we hear nothing from you for a few days.
<!-- endif -->
<!-- if:ticket_mail -->
You can also answer our ticket emails from your own mailbox: your answer is added to the ticket, with its attached files. An email you send to our support address opens a new ticket.
<!-- endif -->

<!-- if:announcements -->
## Announcements

Under Support → Announcements we post news about our services: new services, price changes and planned maintenance, newest first.
<!-- endif -->
<!-- if:server_status -->
## Server status

Support → Server status shows whether our servers work right now, with the squares of the last 30 days and 24 hours. When one has a problem, it is at the top with what we know about it.
<!-- endif -->
<!-- if:contact_page -->
## Contact us

Support → Contact us has our address, phone, email and opening hours, as we fill them in. Press a phone number or an email address to call or write, and **Open in maps** to find us.
<!-- endif -->

## Questions

Write to us at {company_email}.
