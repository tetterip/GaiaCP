# Changelog

Every version of this plugin, newest first. The version is in `plugin.json`; it ships with GaiaCP.

## [1.0.1] - 2026-10-26

- Translated into German, Spanish, French, Italian, Dutch, Polish, Brazilian Portuguese and Turkish (machine translated)

## [1.0.0] - 2026-10-25

- Hosting on Enhance over its REST API (`https://<control panel>/api`, access token as Bearer, the server's username = your organisation's ID): each client becomes a customer organisation with an owner login (their email), each hosting service a subscription to a plan with one website for its domain
- Create (customer, login, Owner member, subscription, website; the subscription is removed again when the website fails), suspend and unsuspend (`isSuspended`), change plan, terminate (the subscription's websites, then the subscription; the customer stays), change the client's login password, plans as packages
- Customers and subscriptions made outside GaiaCP are found by the owner's email and the website's domain
- Client area Log in opens Enhance signed in (the owner's SSO link); Servers → Log in opens the login page
- Usage from the subscription's resources (disk space, transfer, mailboxes, databases, websites) when Enhance lists them
- Lists every customer's subscriptions (`ListsAccounts`), for Servers → Compare with the server
- Built from the open-source Enhance modules for WHMCS and Paymenter, since Enhance's API reference could not be reached; not yet tried against a real Enhance server
