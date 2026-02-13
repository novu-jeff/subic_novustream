@extends('layouts.app')

@section('content')

@if(session('using_default_password'))
<div class="modal fade" id="changePasswordModal" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered modal-md">
        <div class="modal-content">
            <div class="modal-header bg-primary text-white justify-content-center">
                <h5 class="modal-title fw-bold text-uppercase text-center">
                    Security Notice
                </h5>
            </div>
            <div class="modal-body text-center py-5">
                <p class="fs-5">
                    You are using a default password. Please change it now to secure your account.
                </p>

                <div class="d-flex justify-content-center gap-3 mt-4">
                    <a href="{{ route('profile.index', ['user_type' => 'concessionaire']) }}"
                       class="btn btn-primary fw-bold text-uppercase px-4 py-2">
                        Change Password
                    </a>
                </div>
            </div>
        </div>
    </div>
</div>


<script>
document.addEventListener('DOMContentLoaded', function () {
    const modalEl = document.getElementById('changePasswordModal');
    if (modalEl) {
        const modal = new bootstrap.Modal(modalEl, {
            backdrop: 'static',
            keyboard: false
        });
        modal.show();
    }
});
</script>
@endif

    <main class="main">
        <div class="responsive-wrapper">
            <div class="main-header">
                <h1>Account Overview</h1>
            </div>
            <div class="inner-content mt-5 pb-5">

                @php

                    $currentDate = \Carbon\Carbon::now();

                    foreach ($sc_discounts as $discount) {

                        $startDate = $discount['effective_date'] ?? null;
                        $endDate = $discount['expired_date'] ?? null;

                        if ($startDate && $endDate) {
                            $account_no = $discount['account_no'];
                            $startDate = \Carbon\Carbon::parse($startDate);
                            $endDate = \Carbon\Carbon::parse($endDate);
                            if($currentDate->between($startDate, $endDate) && $currentDate->diffInMonths($endDate, false) <= 1) {
                                echo '<div class="blinking alert alert-danger text-uppercase text-center mb-3 fw-medium" style="word-spacing: 4px; letter-spacing: 0.1em;">Senior citizen discount for account <span class="fst-italic fw-bold text-decoration-underline" style="text-underline-offset: 5px;">' . $account_no . '</span> will be expiring on ' . $endDate->format('F d, Y') . '</div>';
                            }
                        }
                    }
                @endphp

                @if($data->accounts)
                    <div class="row pb-5">
                        <div class="col-12 col-md-6 mb-3">
                            <div class="bg-info mt-1 p-3 text-uppercase fw-bold text-white fs-5">
                                Payment Due Date:
                                <span class="ms-2 text-decoration-underline">
                                    @if($statement['total'] == 0)
                                        N/A
                                    @elseif($statement['total'] != 0)
                                        {{\Carbon\Carbon::parse($statement['due_date'])->format('F d, Y')}}
                                    @else
                                        Already Paid
                                    @endif
                                </span>
                            </div>
                            <div class="card shadow border-0 p-4 mt-3">
                                <div class="card-body">
                                    <div class="mb-3">
                                        <div class="mb-3">
                                            <small class="text-uppercase fw-bold text-muted">[+] Property Owner</small>
                                        </div>
                                        <table class="table table-bordered table-hover">
                                            <tbody>
                                                <tr>
                                                    <th class="text-uppercase fw-bold text-muted">Account Name</th>
                                                    <th class="text-uppercase fw-bold text-muted">{{$my->name}}</th>
                                                </tr>
                                                <tr>
                                                    <th class="text-uppercase fw-bold text-muted">Contact No</th>
                                                    <th class="text-uppercase fw-bold text-muted">{{$my->contact_no}}</th>
                                                </tr>
                                                <tr>
                                                    <th class="text-uppercase fw-bold text-muted">Email</th>
                                                    <th class="text-uppercase fw-bold text-muted">{{$my->email}}</th>
                                                </tr>
                                            </tbody>
                                        </table>
                                    </div>
                                    <hr class="my-4">
                                    <div class="mb-3">
                                        <small class="text-uppercase fw-bold text-muted">[+] Properties</small>
                                    </div>
                                    <div>
                                        @php
                                            $product = env('APP_PRODUCT');
                                        @endphp
                                        <div class="accordion accordion-flush" id="accordionAccountConnection">
                                            @forelse($accounts as $key => $account)
                                                <div class="accordion-item">
                                                    <h2 class="accordion-header" id="heading{{$key}}">
                                                        <button class="accordion-button collapsed text-uppercase fw-bold text-muted" type="button" data-bs-toggle="collapse" data-bs-target="#collapse{{$key}}" aria-expanded="false" aria-controls="collapse{{$key}}">
                                                            {{$account->address}}
                                                        </button>
                                                    </h2>
                                                    <div id="collapse{{$key}}" class="accordion-collapse collapse" aria-labelledby="heading{{$key}}" data-bs-parent="#accordionAccountConnection">
                                                        <div class="accordion-body">
                                                            <table class="table table-bordered table-hover">
                                                                <tbody>
                                                                    <tr>
                                                                        <th class="text-uppercase fw-bold text-muted">Account No:</th>
                                                                        <th class="text-uppercase fw-bold text-muted">{{$account->account_no}}</th>
                                                                    </tr>
                                                                    <tr>
                                                                        <th class="text-uppercase fw-bold text-muted">Meter No:</th>
                                                                        <th class="text-uppercase fw-bold text-muted">{{$account->meter_serial_no}}</th>
                                                                    </tr>
                                                                   @if($product == 'novusurge')
                                                                        <tr>
                                                                            <th class="text-uppercase fw-bold text-muted">Meter Brand: </th>
                                                                            <th class="text-uppercase fw-bold text-muted">{{$account->meter_brand}}</th>
                                                                        </tr>
                                                                        <tr>
                                                                            <th class="text-uppercase fw-bold text-muted">Meter Type: </th>
                                                                            <th class="text-uppercase fw-bold text-muted">{{$account->meter_type}}</th>
                                                                        </tr>
                                                                        <tr>
                                                                            <th class="text-uppercase fw-bold text-muted">Meter Wire: </th>
                                                                            <th class="text-uppercase fw-bold text-muted">{{$account->meter_wire}}</th>
                                                                        </tr>
                                                                        <tr>
                                                                            <th class="text-uppercase fw-bold text-muted">Meter Form: </th>
                                                                            <th class="text-uppercase fw-bold text-muted">{{$account->meter_form}}</th>
                                                                        </tr>
                                                                        <tr>
                                                                            <th class="text-uppercase fw-bold text-muted">Meter Class: </th>
                                                                            <th class="text-uppercase fw-bold text-muted">{{$account->meter_class}}</th>
                                                                        </tr>
                                                                   @endif
                                                                    <tr>
                                                                        <th class="text-uppercase fw-bold text-muted">Property Type: </th>
                                                                        <th class="text-uppercase fw-bold text-muted">{{$account->property_type->name ?? 'N/A'}}</th>
                                                                    </tr>
                                                                    <tr>
                                                                        <th class="text-uppercase fw-bold text-muted">SC No: </th>
                                                                        <th class="text-uppercase fw-bold text-muted">{{$account->rate_code}}</th>
                                                                    </tr>
                                                                    <tr>
                                                                        <th class="text-uppercase fw-bold text-muted">Rate Code: </th>
                                                                        <th class="text-uppercase fw-bold text-muted">{{$account->rate_code}}</th>
                                                                    </tr>
                                                                    <tr>
                                                                        <th class="text-uppercase fw-bold text-muted">Sequence No: </th>
                                                                        <th class="text-uppercase fw-bold text-muted">{{$account->sequence_mp}}</th>
                                                                    </tr>
                                                                    <tr>
                                                                        <th class="text-uppercase fw-bold text-muted">Status: </th>
                                                                        <th class="text-uppercase fw-bold text-muted">{{$account->status}}</th>
                                                                    </tr>
                                                                    <tr>
                                                                        <th class="text-uppercase fw-bold text-muted">SC No: </th>
                                                                        <th class="text-uppercase fw-bold text-muted">{{$account->sc_no}}</th>
                                                                    </tr>
                                                                    @if($product == 'novsurge')
                                                                        <tr>
                                                                            <th class="text-uppercase fw-bold text-muted">Location: </th>
                                                                            <th class="text-uppercase fw-bold text-muted">{{$account->lat_long}}</th>
                                                                        </tr>
                                                                        <tr>
                                                                            <th class="text-uppercase fw-bold text-muted">ERC Seal: </th>
                                                                            <th class="text-uppercase fw-bold text-muted">{{$account->isErcSealed ? 'Yes' : 'No'}}</th>
                                                                        </tr>
                                                                    @endif
                                                                    <tr>
                                                                        <th class="text-uppercase fw-bold text-muted">Date Connected: </th>
                                                                        <th class="text-uppercase fw-bold text-muted">{{\Carbon\Carbon::parse($account->date_connected)->format('M d, Y')}}</th>
                                                                    </tr>
                                                                </tbody>
                                                            </table>
                                                        </div>
                                                    </div>
                                                </div>
                                            @empty
                                                <div class="alert alert-info text-muted text-center text-uppercase">No Account Linked</div>
                                            @endforelse
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                        <div class="col-12 col-md-6 mb-3">
                            <div class="card shadow border-0 p-3">
                                <div class="card-body">
                                    <div class="bg-primary mt-1 p-3 text-uppercase fw-bold text-white fs-5">Statement of Account as of <span class="text-decoration-underline text-offset-2">{{Carbon\Carbon::now()->format('F d, Y')}}</span></div>
                                    <div class="note mt-3 ms-0 fst-italic text-uppercase fw-medium" style="font-size: 12px;"><strong>Disclaimer:</strong> Successful payments will be reflected on the next statement and can be viewed via the <strong>Payment History</strong></div>
                                    <hr class="my-4">
                                    <div class="bg-danger d-flex align-items-center justify-content-between mt-1 p-3 text-uppercase fw-bold text-white">Total Amount Due:
                                        <h3 class="ms-2">
                                            @if($statement['total'] != 0)
                                                PHP {{ number_format($statement['total'] ?? 0, 2) }}
                                            @else
                                                PHP 0.00
                                            @endif
                                        </h3>
                                    </div>
                                    <h3 class="ms-2">
                                            @php
                                            $penalty = $statement['current_bill']['computed_penalty'] ?? 0;
                                            $dueDate = isset($data['current_bill']['due_date'])
                                                            ? \Carbon\Carbon::parse($data['current_bill']['due_date'])
                                                            : null;

                                            $today = \Carbon\Carbon::today();

                                            $applicablePenalty = ($dueDate && $today->gt($dueDate)) ? $penalty : 0;
                                            @endphp
                                        </h3>
                                    </div>

                                    @if(!empty($statement['current_bill_qr']))
                                        <div class="d-flex justify-content-center">
                                            <a href="{{ $statement['current_bill_qr'] }}" class="btn btn-success w-50 mt-3" target="_blank">Pay Online</a>
                                        </div>
                                    @endif
                                    <div class="mt-4 pt-2" style="font-size: 14px;">
                                        <div style="display:none;" id="statement-content">
                                            @forelse($statement['transactions'] as $key => $transactions)
                                                <a target="_blank" href="{{route('account-overview.bills.reference_no', ['reference_no' => $transactions['reference_no']])}}">
                                                    <div class="d-flex justify-content-between pb-3 {{$key == 0 ? 'pt-3' : ''}} mb-3" style="{{$key == 0 ? 'border-top: 3px dotted rgba(0, 0, 0, 0.521);' : ''}} border-bottom: 3px dotted rgba(0, 0, 0, 0.521); cursor: pointer;">
                                                        <div>
                                                            <div>
                                                                {{$transactions['reference_no']}} | {{$transactions['account_no']}}
                                                            </div>
                                                            <div class="text-uppercase">
                                                                {{\Carbon\Carbon::parse($transactions['bill_period_from'])->format('M d, Y')}} - {{\Carbon\Carbon::parse($transactions['bill_period_to'])->format('M d, Y')}}
                                                            </div>
                                                        </div>
                                                        <div class="text-end">
                                                            <div class="fw-bold">
                                                                PHP {{number_format($transactions['amount'], 2)}}
                                                            </div>
                                                        </div>
                                                    </div>
                                                    </a>
                                            @empty
                                                <div class="alert alert-danger text-uppercase text-center text-muted fw-bold" style="font-size: 12px">No Statement Found</div>
                                            @endforelse
                                        </div>
                                    </div>
                                    <div class="text-center">
                                        <a href="javascript:void(0)" id="show-statement" class="text-uppercase fw-medium" style="font-size: 13px">View Statement</a>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                @else
                    <div class="alert alert-primary text-uppercase fw-medium text-center mb-4">No accounts linked yet. Enroll your service account to view bills and pay online.</div>
                    <div class="text-center">
                        <a href="{{ route('account-enrollment.index') }}" class="btn btn-primary px-5 py-3 text-uppercase fw-bold">
                            <i class="bx bx-plus-circle"></i> Enroll Account
                        </a>
                    </div>
                @endif
            </div>
        </div>
    </main>
@endsection

@section('script')
    <script>
        $(function() {
            $('#show-statement').on('click', function() {
            const statementContent = $('#statement-content');
            if (statementContent.is(':visible')) {
                statementContent.slideUp('slow');
                $(this).text('View Statement');
            } else {
                statementContent.slideDown('slow');
                $(this).text('Hide Statement');
            }
            });
        });
    </script>
@endsection
