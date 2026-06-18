<?php

namespace App\Http\Controllers;

use App\Models\BankDetail;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class GuardBankDetailController extends Controller
{
      public function show(Request $request)
    {
        $user        = $request->user();
        $bankDetails = BankDetail::where('user_id', $user->id)->first();
        return response()->json(['bank_details' => $bankDetails]);
    }

    public function store(Request $request)
    {
        $user      = $request->user();
        $validated = $request->validate([
            'bank_name'      => 'required|string|max:100',
            'account_holder' => 'required|string|max:150',
            'account_number' => 'required|string|max:30',
            'account_type'   => 'required|in:Cheque,Savings,Transmission',
            'branch_code'    => 'required|string|max:10',
        ]);

        $bankDetails = BankDetail::create([
            'user_id' => $user->id,
            'bank_name' => $validated['bank_name'],
            'account_holder' => $validated['account_holder'],
            'account_number' => $validated['account_number'],
            'account_type' => $validated['account_type'],
            'branch_code' => $validated['branch_code'],
        ]);

        return response()->json([
            'bank_details' => $bankDetails,
            'message'      => 'Bank details saved successfully.',
        ]);
    }
}
