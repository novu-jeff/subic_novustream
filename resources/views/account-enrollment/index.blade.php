@extends('layouts.app')

@section('content')
<main class="main">
    <div class="responsive-wrapper">
        <div class="main-header d-flex justify-content-between flex-wrap gap-3">
            <h1>Enroll a Service Account</h1>
            <a href="{{ route('account-overview.index') }}" class="btn btn-outline-primary px-4 py-2 text-uppercase">
                <i class="bx bx-left-arrow-alt"></i> Back to Overview
            </a>
        </div>

        @if(session('alert'))
            <div class="alert alert-{{ session('alert')['status'] === 'success' ? 'success' : (session('alert')['status'] === 'error' ? 'danger' : 'info') }} alert-dismissible fade show mt-3" role="alert">
                {{ session('alert')['message'] }}
                <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
            </div>
        @endif

        <div class="card shadow border-0 mt-4">
            <div class="card-body p-4">
                <p class="text-muted mb-4">Enter your Customer Account Number (from your bill) to link it to your profile. You will need to verify by entering your consumption from the last 2 billing periods. You can enroll as many accounts as you need.</p>

                <form id="lookupForm" class="mb-4">
                    @csrf
                    <div class="row g-3 align-items-end">
                        <div class="col-md-6">
                            <label for="account_no" class="form-label fw-bold text-uppercase">Account Number</label>
                            <input type="text" id="account_no" name="account_no" class="form-control form-control-lg"
                                placeholder="Enter account number from your bill"
                                maxlength="20" autocomplete="off">
                            <small class="text-muted">Find this on your water bill</small>
                        </div>
                        <div class="col-md-4">
                            <button type="submit" class="btn btn-primary w-100 py-3 text-uppercase fw-bold" id="lookupBtn">
                                <span class="btn-text">Verify Account</span>
                                <span class="spinner-border spinner-border-sm d-none" role="status" aria-hidden="true"></span>
                            </button>
                        </div>
                    </div>
                </form>

                <div id="lookupMessage" class="alert d-none mb-0"></div>

                <form id="verifyForm" class="d-none" action="{{ route('account-enrollment.verify') }}" method="POST">
                    @csrf
                    <input type="hidden" name="account_no" id="verify_account_no">
                    <hr class="my-4">
                    <h5 class="fw-bold text-uppercase mb-3">Verify Ownership</h5>
                    <p class="text-muted small mb-4">Enter the consumption (cubic meters / m³) for each billing period as shown on your bill:</p>
                    <div id="consumptionInputs" class="row g-3 mb-4"></div>
                    <button type="submit" class="btn btn-success px-5 py-3 text-uppercase fw-bold">
                        <i class="bx bx-check-circle"></i> Enroll Account
                    </button>
                </form>
            </div>
        </div>

        @if($accounts->isNotEmpty())
        <div class="card shadow border-0 mt-4">
            <div class="card-header bg-light fw-bold text-uppercase">Your Linked Accounts</div>
            <div class="card-body">
                <ul class="list-group list-group-flush">
                    @foreach($accounts as $acc)
                    <li class="list-group-item d-flex justify-content-between align-items-center">
                        <div>
                            <strong>{{ $acc->account_no }}</strong>
                            @if($acc->address)
                                <span class="text-muted ms-2">— {{ $acc->address }}</span>
                            @endif
                        </div>
                        <a href="{{ route('account-overview.bills', ['account_no' => $acc->account_no, 'view' => 'unpaid']) }}"
                            class="btn btn-sm btn-outline-primary">View Bills</a>
                    </li>
                    @endforeach
                </ul>
            </div>
        </div>
        @endif
    </div>
</main>
@endsection

@section('script')
<script>
document.addEventListener('DOMContentLoaded', function() {
    const lookupForm = document.getElementById('lookupForm');
    const lookupBtn = document.getElementById('lookupBtn');
    const lookupMessage = document.getElementById('lookupMessage');
    const verifyForm = document.getElementById('verifyForm');
    const consumptionInputs = document.getElementById('consumptionInputs');
    const verifyAccountNo = document.getElementById('verify_account_no');

    lookupForm.addEventListener('submit', function(e) {
        e.preventDefault();
        const accountNo = document.getElementById('account_no').value.trim();
        if (!accountNo) {
            showMessage('Please enter your account number.', 'danger');
            return;
        }

        const btnText = lookupBtn.querySelector('.btn-text');
        const spinner = lookupBtn.querySelector('.spinner-border');
        btnText.classList.add('d-none');
        spinner.classList.remove('d-none');
        lookupBtn.disabled = true;
        lookupMessage.classList.add('d-none');
        verifyForm.classList.add('d-none');

        fetch('{{ route("account-enrollment.lookup") }}', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content,
                'Accept': 'application/json',
            },
            body: JSON.stringify({ account_no: accountNo }),
        })
        .then(r => r.json())
        .then(data => {
            if (data.status === 'ok') {
                showMessage(data.message, 'info');
                verifyAccountNo.value = data.account_no;
                consumptionInputs.innerHTML = data.months.map((m, i) => `
                    <div class="col-12 col-md-4">
                        <label class="form-label fw-bold">${m.label}</label>
                        <input type="number" name="consumption_${i + 1}" min="0" step="1" required
                            class="form-control form-control-lg" placeholder="Enter consumption (m³)">
                    </div>
                `).join('');
                verifyForm.classList.remove('d-none');
            } else if (data.status === 'already_enrolled') {
                showMessage(data.message, 'info');
            } else {
                showMessage(data.message || 'Account not found or cannot be enrolled.', 'danger');
            }
        })
        .catch(err => {
            showMessage('An error occurred. Please try again.', 'danger');
        })
        .finally(() => {
            btnText.classList.remove('d-none');
            spinner.classList.add('d-none');
            lookupBtn.disabled = false;
        });
    });

    function showMessage(text, type) {
        lookupMessage.textContent = text;
        lookupMessage.className = 'alert alert-' + (type === 'danger' ? 'danger' : type === 'success' ? 'success' : 'info') + ' mb-0';
        lookupMessage.classList.remove('d-none');
    }
});
</script>
@endsection
