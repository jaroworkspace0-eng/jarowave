<?php

namespace App\Http\Controllers;

use App\Models\BankDetail;
use Illuminate\Http\Request;

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
        $user_id      = auth()->id();
        $validated = $request->validate([
            'bank_name'      => 'required|string|max:100',
            'account_holder' => 'required|string|max:150',
            'account_number' => 'required|string|max:30',
            'account_type'   => 'required|in:Cheque,Savings,Transmission',
            'branch_code'    => 'required|string|max:10',
        ]);

        $bankDetails = BankDetail::updateOrCreate(
            ['user_id' => $user_id],
            $validated,
        );

        return response()->json([
            'bank_details' => $bankDetails,
            'message'      => 'Bank details saved successfully.',
        ]);
    }
}
