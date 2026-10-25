# Getting started as a provider

Written for businesses that sell subscriptions through the platform. No technical knowledge needed.

## 1. Register your business
Create a customer account, then choose **Become a provider**. Give your business name, the address you want for your storefront (`yourname.platform.com`), your country and a support email. If the platform approves providers by hand you will get an email once you are approved; otherwise you can start straight away.

## 2. Connect Stripe
Open **Settings** in your dashboard and choose **Connect Stripe**. Stripe asks for your business and bank details on its own pages; we never see them. Until Stripe says you can accept payments you can build plans and your storefront, but nobody can subscribe. If you stop halfway we send a reminder after three days.

## 3. Create your plans
In **Plans**, add a plan with a name, a description and a list of what is included. Add one or more prices (monthly, yearly and so on) and an optional free trial. A plan becomes available to customers when you **publish** it. Once a price is live it cannot be edited: add a new price and archive the old one, so existing subscribers keep what they signed up for.

## 4. Build your storefront
In **Storefront**, edit the blocks of your page (headline, about, plans, questions, call to action), reorder them and hide the ones you do not need. **Preview** shows the draft in a new tab; nobody else can see it. **Publish** puts it live; **Unpublish** takes it down. Your plans appear on the page automatically.

## 5. Get paid
When a customer subscribes, Stripe charges their card and pays you out on Stripe's schedule. The platform keeps a commission, shown on every payment as **Platform fee** next to **You receive**. The current rate is on your overview; a change only applies to customers who subscribe after it starts, so your existing subscribers keep the rate they signed up under.

## 6. Run your business
- **Overview**: monthly recurring revenue, subscribers gained and lost, failed payments and how well they are being recovered.
- **Subscribers** and **Payments**: who pays what. You can refund a payment fully or partly; the matching part of the platform fee is returned too.
- **Invoices**: every invoice, including the ones that failed.
- **Reports**: revenue, subscribers and failed payments by month or day, with CSV downloads for your accountant.

## When a payment fails
We retry automatically one, three, five and seven days later and email your customer each time, with a link to update their card. If it is still unpaid after the grace period, the subscription is cancelled or paused (the platform decides which). You can retry an invoice yourself or mark it resolved if the customer paid another way. Updating the card triggers an immediate retry.

## Disputes
If a customer's bank opens a dispute, the payment shows a **Dispute** note with the date you must respond by. Respond in your Stripe dashboard (**Settings, Open Stripe dashboard**). If you lose, Stripe takes the money back and the platform fee on that payment is returned to you.

## If you pause or are suspended
Billing stops and the storefront shows as unavailable until you are active again. Existing subscribers are not charged while you are paused.
