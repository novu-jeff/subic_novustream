@extends('layouts.app')

@section('content')
    <main class="main">
        <div class="responsive-wrapper">
            <div class="main-header d-flex justify-content-between">
                <h1>{{$filter}} Bills</h1>
                <div class="d-flex align-items-center gap-3">
                    <a href="{{ route('previous-billing.upload') }}"
                        class="btn btn-outline-primary px-5 py-3 text-uppercase">
                         Upload Billing
                     </a>
                    <a href="{{ route('payments.index', ['filter' => $filter === 'partial' ? 'unpaid' : 'partial']) }}"
                        class="btn btn-primary px-5 py-3 text-uppercase">
                         View {{ $filter === 'partial' ? 'Unpaid' : 'Partial' }}
                     </a>
                </div>
            </div>
            <div class="inner-content mt-5 pb-5 mb-5">
                <div class="row mb-4">
                    <div class="col-12 col-md-1">
                        <label class="mb-1">Show Entries</label>
                        <select name="entries" id="entries" class="form-select text-uppercase dropdown-toggle">
                            @foreach([10, 25, 50, 100, 200, 250, 350, 400, 450, 500] as $entry)
                                <option value="{{ $entry }}" {{ $entries == $entry ? 'selected' : '' }}>
                                    {{ $entry }}
                                </option>
                            @endforeach
                        </select>
                    </div>
                    <div class="col-12 col-md-2 mb-3">
                        <label class="mb-1">Filter</label>
                        <select name="filter" id="filter" class="form-select text-uppercase dropdown-toggle">
                            <option value="unpaid" {{$filter == 'unpaid' ? 'selected' : ''}}>UnPaid</option>
                            <option value="partial" {{$filter == 'partial' ? 'selected' : ''}}>Partial</option>
                            <option value="paid" {{$filter == 'paid' ? 'selected' : ''}}>Paid</option>
                        </select>
                    </div>
                    <div class="col-12 col-md-3 mb-3">
                        <label class="mb-1">Zone</label>
                        <select name="zone_no" id="zone_no" class="form-select text-uppercase dropdown-toggle">
                            <option value="all">All Zones</option>
                            @forelse($zones as $targetedZone)
                                <option value="{{$targetedZone->zone}}" {{$targetedZone->zone == $zone ? 'selected' : ''}}> {{$targetedZone->zone . ' - ' . $targetedZone->area}} </option>
                            @empty
                                <option value="">No Zones Available</option>
                            @endforelse
                        </select>
                    </div>
                    <div class="col-12 col-md-3 mb-3">
                        <label class="mb-1">Reading Month</label>
                        <input type="month" name="month" id="date" class="form-control" value="{{$date}}">
                    </div>
                    <div class="col-12 col-md-3">
                        <label class="mb-1">Search <span class="text-muted ms-1">[account no | name]</span></label>
                        <div class="position-relative">
                            <input
                                type="text"
                                name="search"
                                id="search"
                                class="form-control pe-5"
                                value="{{ $toSearch }}"
                                placeholder=""
                            >

                            @if(!empty($toSearch))
                                <button
                                    type="button"
                                    id="clear-search"
                                    class="btn position-absolute top-50 end-0 translate-middle-y me-2 p-0 text-muted"
                                    style="border: none; background: none; font-size: 1.2rem;"
                                    aria-label="Clear search"
                                >
                                    &times;
                                </button>
                            @endif
                        </div>
                    </div>
                </div>
                <div class="table-responsive">
                    <table class="table table-bordered table-striped w-100 mt-4">
                        <thead>
                            <tr>
                                <th>#</th>
                                <th>Account No</th>
                                <th>Name</th>
                                <th>Zone</th>
                                <th>Billing Period</th>
                                <th>Reading Date</th>
                                <th>Bill Date</th>
                                <th>Amount</th>
                                <th>Status</th>
                                <th>Due Date</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            @forelse ($data as $index => $row)
                                <tr>
                                    <td>{{ $index + 1 }}</td>
                                    <td>{{ $row->bill_account_no ?? $row->reading->account_no ?? 'N/A' }}</td>
                                    <td>{{ $row->bill_owner_name ?? $row->reading?->concessionaire?->user?->name ?? 'N/A' }}</td>
                                    <td>{{ $row->reading->zone ?? 'N/A' }}</td>
                                    <td>
                                        @if ($row->bill_period_from && $row->bill_period_to)
                                            {{ \Carbon\Carbon::parse($row->bill_period_from)->format('M d, Y') }}
                                            TO
                                            {{ \Carbon\Carbon::parse($row->bill_period_to)->format('M d, Y') }}
                                        @else
                                            N/A
                                        @endif
                                    </td>
                                    <td>
                                        {{ !empty($row->bill_period_to)
                                            ? \Carbon\Carbon::parse($row->bill_period_to)->format('M d, Y')
                                            : 'N/A' }}
                                    </td>
                                    <td>
                                        {{ !empty($row->bill_period_to)
                                            ? \Carbon\Carbon::parse($row->bill_period_to)->format('M d, Y')
                                            : 'N/A' }}
                                    </td>
                                    @php
                                        $partialPaid = (float)($row->partial_payment ?? 0);
                                        if ($partialPaid <= 0 && !$row->isPaid && $row->isPartial) {
                                            $partialPaid = (float)($row->amount_paid ?? 0);
                                        }
                                        $remaining = max(
                                            (float)($row->total ?? 0) - $partialPaid,
                                            0
                                        );
                                        $amountToShow = $row->isPartial ? $remaining : (float)($row->total ?? 0);
                                    @endphp
                                    <td>₱{{ number_format($amountToShow, 2) }}</td>
                                    <td>
                                        @if($row->isPaid)
                                            <span class="badge bg-primary">Paid</span>
                                        @elseif($row->isPartial)
                                            <span class="badge bg-warning text-dark">Partial</span>
                                        @else
                                            <span class="badge bg-danger">Unpaid</span>
                                        @endif
                                    </td>
                                    <td>
                                        {{ !empty($row->due_date)
                                            ? \Carbon\Carbon::parse($row->due_date)->format('M d, Y')
                                            : 'N/A' }}
                                    </td>
                                    <td>
                                        <div class="d-flex align-items-center gap-2">
                                            @if (!$row->isPaid)
                                                <a href="{{ route('payments.pay', ['reference_no' => $row->reference_no]) }}"
                                                class="btn btn-primary text-white text-uppercase fw-bold">
                                                    <i class="bx bx-credit-card-alt"></i>
                                                </a>
                                            @else
                                                <a target="_blank" href="{{ route('reading.orshow', $row->reference_no) }}"
                                                class="btn btn-primary text-white text-uppercase fw-bold"
                                                id="show-btn" data-id="{{ $row->id }}">
                                                    <i class="bx bx-receipt"></i>
                                                </a>
                                            @endif
                                        </div>
                                    </td>
                                </tr>
                            @empty
                            <tr>
                                <td colspan="13">
                                    <div class="text-uppercase text-center">No Data Found</div>
                                </td>
                            </tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
                <div class="w-100 mt-4">
                    {{ $data->links() }}
                </div>
            </div>
        </div>
    </main>
@endsection

@section('script')
<script>
    $(function () {
        function updateUrl() {
            const params = new URLSearchParams(window.location.search);

            ['search', 'entries', 'filter', 'zone_no', 'date'].forEach(id => {
                const val = $('#' + id).val();
                const key = id === 'zone_no' ? 'zone' : id;

                val ? params.set(key, val) : params.delete(key);
            });

            window.location.href = window.location.pathname + '?' + params.toString();
        }

        $('#search, #entries, #filter, #zone_no, #date').on('change', updateUrl);

        $('#clear-search').on('click', function () {
            $('#search').val('');
            updateUrl();
        });

        $('#download-summary').on('click', function() {
        const type = $('#print').val();
        const date = $('#date').val();
        const zone = $('#zone_no').val() === 'all' ? '' : $('#zone_no').val();

        if (!type) {
            alert('Please select summary type');
            return;
        }

        const url = `/admin/reports/download?type=${type}&date=${date}&zone=${zone}`;
        window.location.href = url;
    });
    });
</script>
@endsection
