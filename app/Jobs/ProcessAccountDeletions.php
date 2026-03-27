<?php

namespace App\Jobs;

use App\Models\AccountDeletionRequest;
use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

class ProcessAccountDeletions implements ShouldQueue
{
     use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * Create a new job instance.
     */
    public function __construct()
    {
        //
    }

    /**
     * Execute the job.
     */
    public function handle(): void
    {
        $requests = AccountDeletionRequest::where('status', 'pending')
            ->where('scheduled_deletion_at', '<=', now())
            ->get();

        foreach ($requests as $deletion) {
            try {
                $deletion->update(['status' => 'processing']);

                $user = User::find($deletion->user_id);

                if ($user) {
                    // Revoke tokens
                    $user->tokens()->delete();

                    // Anonymise/delete personal data
                    $user->update([
                        'name'       => 'Deleted User',
                        'email'      => 'deleted_' . $user->id . '@deleted.com',
                        'phone'      => null,
                        'password'   => bcrypt(str()->random(32)),
                        'is_active'  => 0,
                    ]);

                    // Delete employee record
                    $user->employee?->delete();
                }

                $deletion->update([
                    'status'            => 'deleted',
                    'processed_at'      => now(),
                    'processed_by'      => null,
                    'processed_by_type' => 'system',
                ]);

                // Send final confirmation
                Mail::raw(
                    "Hi {$deletion->name},\n\nYour Echo Link account and all associated data has been permanently deleted as requested.\n\nThank you for using Echo Link.\n\nManagement",
                    function ($message) use ($deletion) {
                        $message->to($deletion->email)
                                ->subject('Account Deleted - Echo Link');
                    }
                );

            } catch (\Exception $e) {
                Log::error("Failed to delete account for request {$deletion->id}: " . $e->getMessage());
            }
        }
    }
}
