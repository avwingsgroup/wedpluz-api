<?php

/*
| Customer routes for vendors (personal actions), communities, messaging,
| notifications and Pluz Planner. Included inside the customer group.
*/

use App\Http\Controllers\Api\V1\CommunityController;
use App\Http\Controllers\Api\V1\MessagingController;
use App\Http\Controllers\Api\V1\NotificationController;
use App\Http\Controllers\Api\V1\PlannerController;
use App\Http\Controllers\Api\V1\VendorController;
use Illuminate\Support\Facades\Route;

// ---- Vendors -----------------------------------------------------------------
Route::post('works/{id}/like', [VendorController::class, 'like'])->name('works.like');
Route::post('vendors/{id}/reviews', [VendorController::class, 'createReview'])->name('vendors.reviews.create');
Route::put('vendors/{id}/reviews/{reviewId}', [VendorController::class, 'updateReview'])->name('vendors.reviews.update');
Route::delete('vendors/{id}/reviews/{reviewId}', [VendorController::class, 'deleteReview'])->name('vendors.reviews.delete');
Route::post('vendors/{id}/save', [VendorController::class, 'save'])->name('vendors.save');
Route::delete('vendors/{id}/save', [VendorController::class, 'unsave'])->name('vendors.unsave');
Route::post('vendors/{id}/contact', [VendorController::class, 'contact'])->name('vendors.contact');

// ---- Communities ---------------------------------------------------------------
Route::get('communities', [CommunityController::class, 'index'])->name('communities.index');
Route::post('communities', [CommunityController::class, 'store'])->name('communities.store');
Route::post('communities/join/{code}', [CommunityController::class, 'joinByCode'])->name('communities.join-code');
Route::get('communities/{id}', [CommunityController::class, 'show'])->name('communities.show');
Route::put('communities/{id}', [CommunityController::class, 'update'])->name('communities.update');
Route::patch('communities/{id}', [CommunityController::class, 'personalize'])->name('communities.personalize');
Route::post('communities/{id}/join', [CommunityController::class, 'join'])->name('communities.join');
Route::post('communities/{id}/leave', [CommunityController::class, 'leave'])->name('communities.leave');
Route::post('communities/{id}/invite-link', [CommunityController::class, 'inviteLink'])->name('communities.invite-link');
Route::get('communities/{id}/members', [CommunityController::class, 'members'])->name('communities.members');
Route::post('communities/{id}/members', [CommunityController::class, 'invite'])->name('communities.members.invite');
Route::patch('communities/{id}/members/{userId}', [CommunityController::class, 'changeRole'])->name('communities.members.role');
Route::delete('communities/{id}/members/{userId}', [CommunityController::class, 'removeMember'])->name('communities.members.remove');
Route::get('communities/{id}/statuses', [CommunityController::class, 'statuses'])->name('communities.statuses');
Route::post('communities/{id}/statuses', [CommunityController::class, 'postStatus'])->name('communities.statuses.create');
Route::get('communities/{id}/media', [CommunityController::class, 'media'])->name('communities.media');
Route::get('communities/{id}/live', [CommunityController::class, 'live'])->name('communities.live');
Route::post('communities/{id}/live', [CommunityController::class, 'startLive'])->name('communities.live.start');
Route::post('statuses/{statusId}/like', [CommunityController::class, 'likeStatus'])->name('statuses.like');
Route::post('statuses/{statusId}/view', [CommunityController::class, 'viewStatus'])->name('statuses.view');
Route::delete('statuses/{statusId}', [CommunityController::class, 'deleteStatus'])->name('statuses.delete');
Route::patch('live/{liveId}', [CommunityController::class, 'updateLive'])->name('live.update');
Route::post('live/{liveId}/end', [CommunityController::class, 'endLive'])->name('live.end');
Route::post('live/{liveId}/join', [CommunityController::class, 'joinLive'])->name('live.join');
Route::get('live/{liveId}/requests', [CommunityController::class, 'liveRequests'])->name('live.requests');
Route::post('live/{liveId}/requests', [CommunityController::class, 'requestLive'])->name('live.requests.create');
Route::patch('live/{liveId}/requests/{requestId}', [CommunityController::class, 'decideLive'])->name('live.requests.decide');

// ---- Messaging -------------------------------------------------------------------
Route::get('conversations', [MessagingController::class, 'index'])->name('conversations.index');
Route::post('conversations', [MessagingController::class, 'start'])->name('conversations.start');
Route::get('conversations/{id}', [MessagingController::class, 'show'])->name('conversations.show');
Route::patch('conversations/{id}', [MessagingController::class, 'update'])->name('conversations.update');
Route::delete('conversations/{id}', [MessagingController::class, 'destroy'])->name('conversations.destroy');
Route::get('conversations/{id}/messages', [MessagingController::class, 'messages'])->name('conversations.messages');
Route::post('conversations/{id}/messages', [MessagingController::class, 'send'])->name('conversations.send');
Route::post('conversations/{id}/read', [MessagingController::class, 'read'])->name('conversations.read');
Route::patch('conversations/{id}/request', [MessagingController::class, 'respondRequest'])->name('conversations.request');
Route::get('conversations/{id}/media', [MessagingController::class, 'media'])->name('conversations.media');
Route::get('messages/unread-count', [MessagingController::class, 'unreadCount'])->name('messages.unread-count');
Route::delete('messages/{id}', [MessagingController::class, 'deleteMessage'])->name('messages.delete');
Route::post('messages/{id}/reactions', [MessagingController::class, 'react'])->name('messages.react');
Route::post('messages/{id}/star', [MessagingController::class, 'star'])->name('messages.star');
Route::post('messages/{id}/pin', [MessagingController::class, 'pin'])->name('messages.pin');

// ---- Notifications --------------------------------------------------------------------
Route::get('notifications', [NotificationController::class, 'index'])->name('notifications.index');
Route::get('notifications/unread-count', [NotificationController::class, 'unreadCount'])->name('notifications.unread-count');
Route::post('notifications/read-all', [NotificationController::class, 'readAll'])->name('notifications.read-all');
Route::patch('notifications/{id}/read', [NotificationController::class, 'read'])->name('notifications.read');
Route::post('notifications/{id}/actions', [NotificationController::class, 'act'])->name('notifications.act');
Route::delete('notifications/{id}', [NotificationController::class, 'destroy'])->name('notifications.destroy');

// ---- Pluz Planner -----------------------------------------------------------------------
Route::prefix('planner')->name('planner.')->group(function () {
    Route::get('assets', [PlannerController::class, 'assets'])->name('assets');
    Route::delete('assets/{id}', [PlannerController::class, 'deleteAsset'])->name('assets.delete');
    Route::get('chats', [PlannerController::class, 'chats'])->name('chats');
    Route::post('chats', [PlannerController::class, 'createChat'])->name('chats.create');
    Route::get('chats/{id}', [PlannerController::class, 'showChat'])->name('chats.show');
    Route::delete('chats/{id}', [PlannerController::class, 'deleteChat'])->name('chats.delete');
    Route::post('chats/{id}/messages', [PlannerController::class, 'sendMessage'])->name('chats.messages');
    Route::get('menus', [PlannerController::class, 'menus'])->name('menus');
    Route::post('menus', [PlannerController::class, 'createMenu'])->name('menus.create');
    Route::get('menus/{id}', [PlannerController::class, 'showMenu'])->name('menus.show');
    Route::put('menus/{id}', [PlannerController::class, 'updateMenu'])->name('menus.update');
    Route::get('hall-layouts', [PlannerController::class, 'layouts'])->name('hall-layouts');
    Route::post('hall-layouts', [PlannerController::class, 'createLayout'])->name('hall-layouts.create');
    Route::get('hall-layouts/{id}', [PlannerController::class, 'showLayout'])->name('hall-layouts.show');
    Route::put('hall-layouts/{id}', [PlannerController::class, 'updateLayout'])->name('hall-layouts.update');
    Route::post('generations', [PlannerController::class, 'generate'])->name('generations');
});
