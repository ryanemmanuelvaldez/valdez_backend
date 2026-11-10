<?php
defined('PREVENT_DIRECT_ACCESS') OR exit('No direct script access allowed');
/**
 * ------------------------------------------------------------------
 * LavaLust - an opensource lightweight PHP MVC Framework
 * ------------------------------------------------------------------
 *
 * MIT License
 *
 * Copyright (c) 2020 Ronald M. Marasigan
 *
 * Permission is hereby granted, free of charge, to any person obtaining a copy
 * of this software and associated documentation files (the "Software"), to deal
 * in the Software without restriction, including without limitation the rights
 * to use, copy, modify, merge, publish, distribute, sublicense, and/or sell
 * copies of the Software, and to permit persons to whom the Software is
 * furnished to do so, subject to the following conditions:
 *
 * The above copyright notice and this permission notice shall be included in
 * all copies or substantial portions of the Software.
 *
 * THE SOFTWARE IS PROVIDED "AS IS", WITHOUT WARRANTY OF ANY KIND, EXPRESS OR
 * IMPLIED, INCLUDING BUT NOT LIMITED TO THE WARRANTIES OF MERCHANTABILITY,
 * FITNESS FOR A PARTICULAR PURPOSE AND NONINFRINGEMENT. IN NO EVENT SHALL THE
 * AUTHORS OR COPYRIGHT HOLDERS BE LIABLE FOR ANY CLAIM, DAMAGES OR OTHER
 * LIABILITY, WHETHER IN AN ACTION OF CONTRACT, TORT OR OTHERWISE, ARISING FROM,
 * OUT OF OR IN CONNECTION WITH THE SOFTWARE OR THE USE OR OTHER DEALINGS IN
 * THE SOFTWARE.
 *
 * @package LavaLust
 * @author Ronald M. Marasigan <ronald.marasigan@yahoo.com>
 * @since Version 1
 * @link https://github.com/ronmarasigan/LavaLust
 * @license https://opensource.org/licenses/MIT MIT License
 */

/*
| -------------------------------------------------------------------
| URI ROUTING
| -------------------------------------------------------------------
| Here is where you can register web routes for your application.
|
|
*/
/** @var object $router **/

$router->get('/', 'Welcome::index');

$router->post('/api/auth/register', 'Auth::register');
$router->post('/api/auth/login', 'Auth::login');
$router->get('/api/auth/me', 'Auth::me');
$router->post('/api/auth/logout', 'Auth::logout');
$router->post('/api/auth/forgot-password', 'Auth::forgot_password');
$router->post('/api/auth/reset-password', 'Auth::reset_password');
$router->post('/api/auth/send-code', 'Auth::send_verification_code');
$router->post('/api/auth/verify', 'Auth::verify');

$router->get('/api/listings', 'Listings::index');
$router->get('/api/listings/{id}/photo', 'Listings::photo');
$router->post('/api/listings', 'Listings::create');
$router->post('/api/listings/{id}/requests', 'RentalRequests::create');
$router->get('/api/tenant/requests', 'RentalRequests::mine');
$router->post('/api/tenant/requests/{id}/checkout', 'Payments::checkout');
$router->get('/api/tenant/payments', 'Payments::mine');
$router->get('/api/tenant/installments', 'Payments::installments');
$router->post('/api/tenant/installments/{id}/checkout', 'Payments::checkout_installment');
$router->post('/api/tenant/requests/{id}/physical-payment', 'Payments::request_physical_payment');
$router->post('/api/tenant/installments/{id}/physical-payment', 'Payments::request_installment_physical_payment');
$router->post('/api/tenant/requests/{id}/cash-payment', 'Payments::request_cash_payment');
$router->post('/api/tenant/installments/{id}/cash-payment', 'Payments::request_installment_cash_payment');
$router->get('/api/tenant/announcements', 'Community::announcements');
$router->get('/api/tenant/reviews', 'Community::reviews');
$router->post('/api/tenant/reviews', 'Community::create_review');
$router->get('/api/tenant/tickets', 'Community::tickets');
$router->post('/api/tenant/tickets', 'Community::create_ticket');
$router->get('/api/owner/announcements', 'Community::announcements');
$router->get('/api/owner/tickets', 'Community::tickets');
$router->post('/api/owner/tickets', 'Community::create_ticket');
$router->get('/api/account/announcements', 'Community::announcements');
$router->get('/api/account/reviews', 'Community::reviews');
$router->post('/api/account/reviews', 'Community::create_review');
$router->get('/api/account/tickets', 'Community::tickets');
$router->post('/api/account/tickets', 'Community::create_ticket');
$router->post('/api/webhooks/xendit', 'Payments::xendit_webhook');
$router->get('/api/owner/listings', 'Listings::mine');
$router->get('/api/owner/property-locations', 'Listings::locations_mine');
$router->post('/api/owner/listings/{id}/update', 'Listings::update_mine');
$router->get('/api/owner/requests', 'RentalRequests::for_owner');
$router->post('/api/owner/requests/{id}/review', 'RentalRequests::review');
$router->get('/api/owner/renters', 'RentalRequests::renters');
$router->post('/api/owner/renters/{id}/update', 'RentalRequests::update_renter');
$router->post('/api/owner/renters/{id}/end', 'RentalRequests::end_renter');
$router->get('/api/owner/payment-profile', 'Payments::owner_payment_profile');
$router->post('/api/owner/payment-profile', 'Payments::save_owner_payment_profile');
$router->get('/api/owner/payments', 'Payments::owner_payments');
$router->get('/api/owner/payments/{id}/receipt', 'Payments::owner_payment_receipt');
$router->get('/api/owner/income', 'Payments::owner_income');
$router->get('/api/owner/sales-analytics', 'Payments::owner_sales_analytics');
$router->get('/api/owner/ratings', 'Payments::owner_ratings');
$router->post('/api/owner/payments/{id}/settle', 'Payments::settle_owner_payment');
$router->post('/api/owner/verification-documents', 'OwnerVerification::upload_documents');
$router->get('/api/owner/verification-documents', 'OwnerVerification::documents');
$router->get('/api/account/rent-notifications', 'Community::rent_notifications');
$router->post('/api/account/rent-notifications/{id}/read', 'Community::mark_rent_notification_read');

$router->get('/api/admin/owners', 'Admin::owners');
$router->get('/api/admin/users', 'Admin::users');
$router->get('/api/admin/overview', 'Admin::overview');
$router->post('/api/admin/users', 'Admin::create_user');
$router->post('/api/admin/users/{id}/update', 'Admin::update_user');
$router->post('/api/admin/users/{id}/delete', 'Admin::delete_user');
$router->post('/api/admin/owners/{id}/review', 'Admin::review_owner');
$router->get('/api/admin/owners/{owner_id}/documents/{document_id}', 'Admin::owner_document');
$router->get('/api/admin/listings', 'Admin::listings');
$router->get('/api/admin/property-locations', 'Admin::property_locations');
$router->post('/api/admin/listings', 'Admin::create_listing');
$router->post('/api/admin/listings/{id}/update', 'Admin::update_listing');
$router->post('/api/admin/listings/{id}/delete', 'Admin::delete_listing');
$router->post('/api/admin/listings/{id}/review', 'Admin::review_listing');
$router->post('/api/admin/listings/{id}/featured', 'Admin::set_featured');
$router->get('/api/admin/reservations', 'Admin::reservations');
$router->post('/api/admin/reservations/{id}/review', 'Admin::review_reservation');
$router->get('/api/admin/reviews', 'Admin::reviews');
$router->post('/api/admin/reviews/{id}/moderate', 'Admin::moderate_review');
$router->get('/api/admin/support-tickets', 'Admin::support_tickets');
$router->post('/api/admin/support-tickets/{id}/update', 'Admin::update_support_ticket');
$router->get('/api/admin/announcements', 'Admin::announcements');
$router->post('/api/admin/announcements', 'Admin::save_announcement');
$router->post('/api/admin/announcements/{id}/update', 'Admin::save_announcement');
$router->post('/api/admin/announcements/{id}/delete', 'Admin::delete_announcement');
$router->get('/api/admin/analytics', 'Admin::analytics');
$router->get('/api/admin/sales-analytics', 'Admin::sales_analytics');
$router->get('/api/admin/reports', 'Admin::reports');
$router->get('/api/admin/settings', 'Admin::settings');
$router->post('/api/admin/settings', 'Admin::update_settings');