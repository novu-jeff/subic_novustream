@extends('layouts.app')

@section('content')
    <main class="main">
        <div class="responsive-wrapper">
            <div class="inner-content">
                <div class="d-md-flex justify-content-center gap-5">
                    <div class="mb-5" style="width: 100%">
                        <div class="card shadow border-0 p-2 pb-0 px-3" style="border-radius: 20px;">
                            <div class="card-body">
                                <div class="row">
                                    <div class="col-md-12 mb-3">
                                        <label for="zone_no" class="form-label">Zone</label>
                                        <select name="zone_no" id="zone_no" class="form-select text-uppercase dropdown-toggle">
                                            @if($showAllOption)
                                                <option value="all"> All Zones </option>
                                            @endif
                                            @foreach($zones as $item)
                                                <option value="{{ $item->zone }}">
                                                    {{ $item->zone . ' - ' . $item->area }}
                                                </option>
                                            @endforeach
                                        </select>
                                        @error('zone_no')
                                            <div class="invalid-feedback">{{ $message }}</div>
                                        @enderror
                                    </div>
                                    <div class="col-md-6 mb-3">
                                        <label for="filter" class="form-label">Filter</label>
                                        <select name="filter" id="filter" class="form-select dropdown-toggle">
                                            <option value="50"> 50 </option>
                                            <option value="100"> 100 </option>
                                            <option value="300"> 300 </option>
                                            <option value="500"> 500 </option>
                                            <option value="700"> 700 </option>
                                            <option value="1000"> 1000 </option>
                                        </select>
                                        @error('filter')
                                            <div class="invalid-feedback">{{ $message }}</div>
                                        @enderror
                                    </div>
                                    <div class="col-md-6 mb-3">
                                        <label for="search_by" class="form-label">Search By</label>
                                        <select name="search_by" id="search_by" class="form-select dropdown-toggle text-uppercase">
                                        <option value="name">Name</option>
                                        <option value="account_no">Account No</option>
                                        <option value="meter_serial_no">Meter No</option>
                                        <option value="read">Read Accounts</option>
                                        <option value="unread">Unread Accounts</option>
                                    </select>

                                        @error('search_by')
                                            <div class="invalid-feedback">{{ $message }}</div>
                                        @enderror
                                    </div>
                                    <div class="col-md-12 mb-3">
                                        <label for="search" class="form-label">Search</label>
                                        <input type="text" name="search" id="search" class="form-control text-uppercase" placeholder="Tap to search...">
                                        @error('search')
                                            <div class="invalid-feedback">{{ $message }}</div>
                                        @enderror
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                    <div class="mb-5" style="width: 100%">
                        <div class="concessionaire-result position-sticky top-0 bg-white z-2 py-2">

                        </div>
                        <div class="concessionaire-list" style="max-height: calc(100vh - 300px); overflow-y: auto;">

                        </div>
                    </div>
                </div>
            </div>
        </div>
        <div class="modal fade " id="accountModal" data-bs-backdrop="static" tabindex="-1" aria-labelledby="accountModalLabel" aria-hidden="true">
            <div class="modal-dialog modal-dialog-centered text-uppercase" da>
                <div class="modal-content text-uppercase">
                    <div class="modal-header px-4 text-uppercase">
                        <h5 class="modal-title text-uppercase" id="accountModalLabel"></h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                    </div>
                    <div class="modal-body p-4 text-uppercase">
                        <div id="accountModalBody">

                        </div>
                    </div>
                </div>
            </div>
        </div>
    </main>
    <style>
        .h-extend {
            height: 50px;
        }
    </style>
@endsection

@section('script')
<script>
    let selectedAccountNo = null;
    let offset = 0;
    const limit = 50;
    let isLoading = false;
    let hasMoreData = true;
    let didScrollToPreviousAccount = false;
    const isReRead = '{{$isReRead ? 'true' : 'false'}}';
    const reference_no = '{{$reference_no}}';
    const recentReading = @json(session('recent_reading'))

    $(function () {


        @if (session('alert'))
            setTimeout(() => {
                const { status, message } = @json(session('alert'));
                alert(status, message);
            }, 100);
        @endif

        checkIsReRead();

        function checkIsReRead() {

            if(isReRead == 'true') {
                $.get('{{ route(Route::currentRouteName()) }}', {
                    reference_no: reference_no,
                    isReRead: isReRead,
                }, function (response) {
                    selectedAccountNo = response.account_no;
                    const previousReading = parseFloat(response.previous_reading ?? 0);
                    const presentReading = parseFloat(response.present_reading ?? 0);
                    const consumption = parseFloat(response.consumption ?? 0);
                    const suggestedNextMonth = response.suggestedNextMonth;
                    const sc_expired_date = response.sc_expired_date;
                    const isHighConsumption = response.isHighConsumption ? true : false;

                    $('#accountModal .modal-title').html('Proceed Re-Reading');

                    let modalContent = `
                        <p class="mb-1" offline-accountNo><strong class="text-uppercase">Account No:</strong> ${response.account_no}</p>
                        <p class="mb-1 offline-name"><strong class="text-uppercase">Name:</strong> ${response.name ?? 'N/A'}</p>
                        <p class="mb-1" offline-address><strong class="text-uppercase">Address:</strong> ${response.address ?? 'N/A'}</p>
                        `
                        if(sc_expired_date != null) {
                            modalContent += `<div class="text-uppercase fw-bold mt-3  text-center py-2 px-3 alert alert-warning">Senior citizen discount will be expired on ${sc_expired_date}</div`
                        }
                    modalContent+=`
                        <hr>
                        <div class="row mt-3 ">
                            @if(env('IS_TEST_READING'))
                                <div class="col-md-12 mb-3">
                                    <label for="reading_month" class="form-label">Reading Month</label>
                                    <input type="date" class="form-control h-extend" id="reading_month" name="reading_month" value="${suggestedNextMonth}" placeholder="########">
                                </div>
                            @endif
                            <div class="col-md-12 mb-3">
                                <label for="present_reading" class="form-label">Present Reading</label>
                                <input type="number" class="form-control h-extend" id="present_reading" value="${presentReading}" placeholder="########">
                            </div>
                            <div class="col-md-6 mb-3">
                                <label for="previous_reading" class="form-label">Previous Reading</label>
                                <input type="text" class="form-control restricted h-extend" id="previous_reading" value="${previousReading}" readonly>
                            </div>
                            <div class="col-md-6 mb-3">
                                <label for="consumption" class="form-label">Consumption</label>
                                <input type="text" class="form-control restricted h-extend" id="consumption" value="${consumption}" readonly>
                            </div>
                            <div class="col-md-12 mb-3">
                                <label class="form-label d-block">Mark as High Consumption</label>
                                <div class="form-check form-check-inline">
                                    <input class="form-check-input" type="radio" name="is_high_consumption" id="is_high_consumption_yes" value="yes">
                                    <label class="form-check-label" for="is_high_consumption_yes">Yes</label>
                                </div>
                                <div class="form-check form-check-inline">
                                    <input class="form-check-input" type="radio" name="is_high_consumption" id="is_high_consumption_no" value="no" checked>
                                    <label class="form-check-label" for="is_high_consumption_no">No</label>
                                </div>
                            </div>
                            <div class="col-md-12 mb-3" id="highConsumptionNoteWrapper" style="display: none;">
                                <label for="high_consumption_note" class="form-label">Remarks / Notes (if marked as High Consumption)</label>
                                <textarea id="high_consumption_note" class="form-control h-extend" placeholder="Enter remarks..."></textarea>
                            </div>
                        </div>
                        <div class="text-end mt-4">
                            <button type="button" class="btn btn-primary px-5 py-3 text-uppercase fw-bold" id="proceedButton">Proceed</button>
                        </div>
                    `;
                    $('#accountModalBody').html(modalContent);
                    $('.btn-close').remove();
                    const modal = new bootstrap.Modal(document.getElementById('accountModal'), {
                        backdrop: 'static',
                        keyboard: false
                    });

                   modal.show();

                    if (isHighConsumption) {
                        $('input[name="is_high_consumption"][value="yes"]').prop('checked', true);
                        $('input[name="is_high_consumption"][value="no"]').prop('checked', false);
                    } else {
                        $('input[name="is_high_consumption"][value="no"]').prop('checked', true);
                        $('input[name="is_high_consumption"][value="yes"]').prop('checked', false);
                    }
                });
            }

        }

        function fetchAccountData(append = false) {
            if (isLoading || !hasMoreData) return;
            isLoading = true;

            const zone = $('#zone_no').val();
            const filter = $('#filter').val();
            const searchBy = $('#search_by').val();
            const search = $('#search').val();

            $.get('{{ route(Route::currentRouteName()) }}', {
                zone,
                filter,
                search_by: searchBy,
                search,
                offset,
                limit
            }, function (res) {
                isLoading = false;

                const data = Array.isArray(res) ? res : (res.data || []);
                const total = (res && res.total !== undefined) ? res.total : data.length;

                if (!append) {
                    $('.concessionaire-list').empty();
                    offset = 0;
                    hasMoreData = true;
                }

                if (!data.length) {
                    if (!append) {
                        $('.concessionaire-list').html(`
                            <div class="alert alert-danger text-uppercase text-center shadow" role="alert">
                                No records found.
                            </div>
                        `);
                        $('.concessionaire-result').html(`
                            <div class="text-uppercase fw-bold text-muted fst-italic mb-2">
                                Accounts Found: 0
                            </div>
                        `);
                    }
                    hasMoreData = false;
                    return;
                }

                $('.concessionaire-result').html(`
                    <div class="text-uppercase fw-bold text-muted fst-italic mb-2" data-count="${total}">
                        Accounts Found: ${total}
                    </div>
                `);

                data.forEach((account, index) => {
                    const status = account.status; // from concessioner_accounts table
                    console.log('Account Status:', status);
                    const statusColors = {
                        AB: '#ffffff', // ACTIVE (white)
                        BL: '#ffe0b2', // FOR DISCONNECTION (light orange)
                        ID: '#f8d7da', // DISCONNECTED (light red)
                        IV: '#cff4fc', // FOR RECONNECTION (light blue)
                    };

                    const statusNames = {
                        AB: 'ACTIVE BILLED',
                        BL: 'BLACK LISTED',
                        ID: 'INACTIVE DELINQUENT',
                        IV: 'INACTIVE DISCONNECTION	'
                    };

                    const bgColor = statusColors[status] || '#ffffff';
                    const statusName = statusNames[status] || 'UNKNOWN';

                    const dotColors = {
                        AB: '#28a745', // ACTIVE
                        BL: '#ef2121ff', // BLACK LISTED
                        ID: '#dc3545', // INACTIVE DELINQUENT
                        IV: '#155101ff', // INACTIVE DISCONNECTION
                    };


                    const dotColor = dotColors[status] || '#6c757d';

                    const cardStyle = `
                        background-color: ${bgColor};
                        cursor: pointer;
                        position: relative;
                    `;

                    const dot = `
                        <div style="
                            width: 12px;
                            height: 12px;
                            border-radius: 50%;
                            position: absolute;
                            top: 18px;
                            right: 25px;
                            background-color: ${dotColor};
                        " title="${statusName}"></div>
                    `;

                    const html = `
                        <div class="card shadow mb-3 account-card"
                            data-account-no="${account.account_no}"
                            data-index="${offset + index}"
                            style="${cardStyle}"
                            data-account='${JSON.stringify(account)}'>
                            <div class="card-body">
                                ${dot}
                                <h5 class="card-title mb-0 fw-normal">Account No: ${account.account_no}</h5>
                                <hr class="my-2 mb-2">
                                <h5 class="fw-normal">Meter No: ${account.meter_serial_no}</h5>
                                <h4>${account.user ? account.user.name : 'N/A'}</h4>
                                <h5 class="fw-normal text-capitalize">${account.address ?? 'N/A'}</h5>
                                <div class="mt-2 small text-muted fw-bold text-uppercase">${statusName}</div>
                            </div>
                        </div>
                    `;

                    $('.concessionaire-list').append(html);
                });

                offset += data.length;

                if (data.length < limit) {
                    hasMoreData = false;
                }

                // After appending all cards
                $('.account-card').off('click').on('click', function () {
                    const account = $(this).data('account');
                    const status = account.status;

                    const statusNames = {
                        AB: 'ACTIVE BILLED',
                        BL: 'BLACK LISTED',
                        ID: 'INACTIVE DELINQUENT',
                        IV: 'INACTIVE DISCONNECTION'
                    };

                    if (status === 'AB') {
                        // Account is active, proceed with your normal click action
                        console.log('Proceed to account:', account.account_no);
                        // You can redirect or open details modal here
                    } else {
                        // Show popup for non-active accounts
                        alert(`The account is ${statusNames[status] || 'UNKNOWN'}`);
                    }
                });

                if (!didScrollToPreviousAccount && recentReading && !append) {
                    setTimeout(() => {
                        const $target = $(`.account-card[data-account-no="${recentReading.account_no}"]`);
                        if ($target.length) {
                            $('.concessionaire-list').animate({
                                scrollTop: $target.offset().top - $('.concessionaire-list').offset().top + $('.concessionaire-list').scrollTop()
                            }, 500);
                            $target.css('box-shadow', '0 0 10px 5px #007bff');
                        }
                        didScrollToPreviousAccount = true;
                    }, 300);
                }
            }).fail((jqXHR, textStatus, errorThrown) => {
                isLoading = false;
                console.error('Error fetching data:', textStatus, errorThrown);
            });
        }

        function fetchRecentReading() {
            $.get('{{ route(Route::currentRouteName()) }}', {
                isGetRecentReading: true
            }, function(response) {
                if (!$.isEmptyObject(response)) {
                    renderRecentReadingCard(response);
                } else {
                    $('#recent-reading-container').empty();
                }
            }).fail(function(xhr) {
                console.error('Failed to fetch recent reading:', xhr);
            });
        }

        function renderRecentReadingCard(data) {
            if (!data) return;

            const formattedDate = data.timestamp
                ? new Date(data.timestamp).toLocaleString('en-US', {
                    year: 'numeric', month: 'long', day: '2-digit',
                    hour: '2-digit', minute: '2-digit', hour12: true
                }).replace(',', ' at')
                : '';

            const card = $(`
                <div class="card shadow mb-3 account-card mt-4 border-3 border-primary" style="cursor: pointer">
                    <div class="card-body">
                        <button class="btn btn-danger" id="clearRecent" style="position: absolute; top: 10px; right: 10px;">
                            <i class='bx bx-trash'></i>
                        </button>
                        <h5 class="card-title mb-0 fw-normal text-uppercase py-2 fw-bold">Recent Reading</h5>
                        <hr class="my-2 mb-2">
                        <h5 class="fw-normal">Account No: ${data.account_no}</h5>
                        <h4>${data.name}</h4>
                        <h5 class="fw-normal text-capitalize">${data.address}</h5>
                        <small>${formattedDate}</small>
                    </div>
                </div>
            `);

            $('#recent-reading-container').html(card);
        }

        $(document).on('click', '#showReadUnread', function () {
            const $btn = $(this);
            const originalText = $btn.text();
            const monthYear = $('#targetDate').val();

            if (monthYear == '') {
                alert('error', 'No month selected');
                return;
            }

            $btn.text('Please wait...').prop('disabled', true);

            const date = new Date(`${monthYear}-01`);
            const formattedMonthYear = date.toLocaleString('default', { month: 'long', year: 'numeric' });

            $.get('{{ route(Route::currentRouteName()) }}', {
                targetDate: monthYear,
                isGetReadUnread: true
            }, function (response) {
                $('#readUnreadModal').remove();

                const modalHtml = `
                    <div class="modal fade" id="readUnreadModal" tabindex="-1" aria-labelledby="readUnreadModalLabel" aria-hidden="true">
                        <div class="modal-dialog modal-lg">
                            <div class="modal-content">
                                <div class="modal-header">
                                    <h5 class="modal-title">View Read and Unread For ${formattedMonthYear}</h5>
                                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                                </div>
                                <div class="modal-body">
                                    <ul class="nav nav-pills" id="readUnreadTabs" role="tablist">
                                        <li class="nav-item" role="presentation">
                                            <button class="nav-link text-uppercase px-4 active" id="unread-tab" data-bs-toggle="tab" data-bs-target="#unread" type="button" role="tab">Unread</button>
                                        </li>
                                        <li class="nav-item" role="presentation">
                                            <button class="nav-link text-uppercase px-4" id="read-tab" data-bs-toggle="tab" data-bs-target="#read" type="button" role="tab">Read</button>
                                        </li>
                                    </ul>
                                    <div class="tab-content mt-3">
                                        <div class="tab-pane fade show active" id="unread" role="tabpanel">
                                            <div id="unread-list" style="max-height: 600px; overflow-y: auto;"></div>
                                        </div>
                                        <div class="tab-pane fade" id="read" role="tabpanel">
                                            <div id="read-list" style="max-height: 600px; overflow-y: auto;"></div>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>`;

                $('body').append(modalHtml);

                function renderAccountCards(data) {
                    if (!data.length) {
                        return '<div class="text-muted text-center py-3 text-uppercase">No records found.</div>';
                    }

                    return data.map(item => `
                        <div class="card mb-2 shadow-sm">
                            <div class="card-body p-3">
                                <h6 class="card-title mb-1"><strong>${item.name}</strong></h6>
                                <p class="mb-1"><strong>Account No:</strong> ${item.account_no}</p>
                                <p class="mb-1"><strong>Address:</strong> ${item.address}</p>
                                <p class="mb-0"><strong>Meter No:</strong> ${item.meter_no}</p>
                            </div>
                        </div>
                    `).join('');
                }

                $('#unread-list').html(renderAccountCards(response.unread || []));
                $('#read-list').html(renderAccountCards(response.read || []));

                const modal = new bootstrap.Modal(document.getElementById('readUnreadModal'));
                modal.show();
            }).always(function () {
                $btn.text(originalText).prop('disabled', false);
            });
        });

        $(document).on('click', '.account-card', function () {
            // Parse the data-account JSON correctly
            const account = JSON.parse($(this).attr('data-account'));
            const status = account.status;

            const statusNames = {
                AB: 'ACTIVE BILLED',
                BL: 'BLACK LISTED',
                ID: 'INACTIVE DELINQUENT',
                IV: 'INACTIVE DISCONNECTION'
            };

            if (status !== 'AB') {
                alert(`The account is ${statusNames[status] || 'UNKNOWN'}`);
                return; // Stop here for non-AB accounts
            }

            // Proceed only if AB
            selectedAccountNo = account.account_no;
            $('#accountAlert').empty();

            $.get('{{ route(Route::currentRouteName()) }}', {
                account_no: account.account_no,
                isGetPrevious: true,
            }, function (response) {
                const previousReading = parseFloat(response.previous_reading ?? 0);
                const suggestedNextMonth = response.suggestedNextMonth;
                const sc_expired_date = response.sc_expired_date;

                $('#accountModal .modal-title').html('Proceed Reading');

                let modalContent = `
                    <p class="mb-1"><strong class="text-uppercase">Account No:</strong> ${account.account_no}</p>
                    <p class="mb-1"><strong class="text-uppercase">Name:</strong> ${account.user?.name ?? 'N/A'}</p>
                    <p class="mb-1"><strong class="text-uppercase">Address:</strong> ${account.address ?? 'N/A'}</p>
                `;

                if(sc_expired_date != null) {
                    modalContent += `<div class="text-uppercase fw-bold mt-3 text-center py-2 px-3 alert alert-warning">Senior citizen discount will be expired on ${sc_expired_date}</div>`;
                }

                modalContent+=`
                    <hr>
                    <div class="row mt-3">
                        @if(env('IS_TEST_READING'))
                            <div class="col-md-12 mb-3">
                                <label for="reading_month" class="form-label">Reading Month</label>
                                <input type="date" class="form-control h-extend" id="reading_month" name="reading_month" value="${suggestedNextMonth}" placeholder="########">
                            </div>
                        @endif
                        <div class="col-md-12 mb-3">
                            <label for="present_reading" class="form-label">Present Reading</label>
                            <input type="number" class="form-control h-extend" id="present_reading" value="0" placeholder="########">
                        </div>
                        <div class="col-md-6 mb-3">
                            <label for="previous_reading" class="form-label">Previous Reading</label>
                            <input type="text" class="form-control restricted h-extend" id="previous_reading" value="${previousReading}" readonly>
                        </div>
                        <div class="col-md-6 mb-3">
                            <label for="consumption" class="form-label">Consumption</label>
                            <input type="text" class="form-control restricted h-extend" id="consumption" value="0" readonly>
                        </div>
                        <div class="col-md-12 mb-3">
                            <label class="form-label d-block">Mark as High Consumption</label>
                            <div class="form-check form-check-inline">
                                <input class="form-check-input" type="radio" name="is_high_consumption" id="is_high_consumption_yes" value="yes">
                                <label class="form-check-label" for="is_high_consumption_yes">Yes</label>
                            </div>
                            <div class="form-check form-check-inline">
                                <input class="form-check-input" type="radio" name="is_high_consumption" id="is_high_consumption_no" value="no" checked>
                                <label class="form-check-label" for="is_high_consumption_no">No</label>
                            </div>
                        </div>
                        <div class="col-md-12 mb-3" id="highConsumptionNoteWrapper" style="display: none;">
                            <label for="high_consumption_note" class="form-label">Remarks / Notes (if marked as High Consumption)</label>
                            <textarea id="high_consumption_note" class="form-control h-extend" placeholder="Enter remarks..."></textarea>
                        </div>
                    </div>
                    <div class="text-end mt-4">
                        <button type="button" class="btn btn-primary px-5 py-3 text-uppercase fw-bold d-none" id="proceedButton">Proceed</button>
                    </div>
                `;

                $('#accountModalBody').html(modalContent);
                const modal = new bootstrap.Modal(document.getElementById('accountModal'), {
                    backdrop: 'static',
                    keyboard: false
                });
                modal.show();
            });
        });

        $(document).on('input', '#present_reading', function () {
            const present = parseFloat($(this).val()) || 0;
            const previous = parseFloat($('#previous_reading').val()) || 0;
            const consumption = Math.max(present - previous, 0);

            $('#consumption').val(consumption);

            $('#proceedButton').removeClass('d-none');


            // if (present > 0 && present > previous) {
            //     $('#proceedButton').removeClass('d-none');
            // } else {
            //     $('#proceedButton').addClass('d-none');
            // }
        });

        $(document).on('click', '#present_reading', function() {
            $(this).val('');
        });

        $(document).on('click', '#proceedButton', function () {
            const presentReading = $('#present_reading').val();
            const previousReading = $('#previous_reading').val();
            const readingMonth = $('#reading_month').val();
            const is_high_consumption = $('input[name="is_high_consumption"]:checked').val();

            if (!selectedAccountNo) {
                alert('error', 'Account number is missing.');
                return;
            }

            // if (!presentReading || isNaN(presentReading) || Number(presentReading) <= Number(previousReading)) {
            //     alert('error', 'Present reading must be greater than previous reading.');
            //     return;
            // }

            const postData = {
                reading_month: readingMonth,
                account_no: selectedAccountNo,
                previous_reading: previousReading,
                present_reading: presentReading,
                is_high_consumption: is_high_consumption,
                isReRead: isReRead,
                reference_no: reference_no,
                is_high_consumption,
                high_consumption_note: $('#high_consumption_note').val(),
            };

            $.ajax({
                url: '{{ route("reading.store") }}',
                type: 'POST',
                data: JSON.stringify(postData),
                contentType: 'application/json',
                headers: {
                    'X-CSRF-TOKEN': '{{ csrf_token() }}'
                },
                success: function (response) {
                    alert(response.status, response.message);
                    $('#accountModal').modal('hide');
                    if (response.redirect_url) {
                        fetchRecentReading(recentReading);
                        setTimeout(() => {
                            window.open(
                                response.redirect_url,
                                'popupWindow',
                                'width=800,height=800,resizable=no,scrollbars=yes,toolbar=no,menubar=no,location=no,status=no'
                            );
                        }, 2000);
                    } else {
                        offset = 0;
                        hasMoreData = true;
                        fetchAccountData();
                    }
                },
                error: function (xhr) {
                    let errorMsg = 'An error occurred.';
                    if (xhr.responseJSON && xhr.responseJSON.message) {
                        errorMsg = xhr.responseJSON.message;
                    }
                    alert('error', errorMsg);
                }
            });
        });

        $(document).on('click', '#clearRecent', function() {
            $.post('{{ route(Route::currentRouteName()) }}', {
                isClearRecent: true,
                _token: '{{ csrf_token() }}'
            }, function(response) {
            }).fail(function(xhr) {
                console.error('Failed to fetch recent reading:', xhr);
            }).done(function() {
                fetchRecentReading();
            });
        });

        $('#zone_no, #filter, #search_by').on('change', function () {
            offset = 0;
            hasMoreData = true;
            fetchAccountData();
        });

        $('#search').on('keyup', function () {
            clearTimeout($.data(this, 'timer'));
            const wait = setTimeout(() => {
                offset = 0;
                hasMoreData = true;
                fetchAccountData();
            }, 400);
            $(this).data('timer', wait);
        });

        $('.concessionaire-list').on('scroll', function () {
            const $list = $(this);
            if ($list.scrollTop() + $list.innerHeight() >= $list[0].scrollHeight - 20) {
                fetchAccountData(true);
            }
        });

        $(document).on('change', 'input[name="is_high_consumption"]', function() {
            if ($(this).val() === 'yes') {
                $('#highConsumptionNoteWrapper').show();
            } else {
                $('#highConsumptionNoteWrapper').hide();
                $('#high_consumption_note').val('');
            }
        });

        fetchAccountData();
        fetchRecentReading();
    });
</script>

<!-- Offline Mode Logic -->
<script src="https://cdnjs.cloudflare.com/ajax/libs/localforage/1.10.0/localforage.min.js"></script>
<script>
/* ===========================================================
   ⚙️ STARITA OFFLINE MODE ENHANCEMENT
   =========================================================== */
localforage.config({ name: 'StaritaOfflineReadings' });
const STORAGE_KEY = 'offline_readings';
const MASTER_KEY = 'offline_master_data';

function showNotify(type, message, opts = {}) {
  // type: 'success' | 'error' | 'info'
  if (window.Notyf) {
    const notyf = new Notyf({ duration: opts.duration || 3000, ripple: true });
    if (type === 'success') notyf.success(message);
    else if (type === 'error') notyf.error(message);
    else notyf.open({ type: 'info', message });
  } else if (window.toastr) {
    if (type === 'success') toastr.success(message);
    else if (type === 'error') toastr.error(message);
    else toastr.info(message);
  } else {
    // fallback
    try { console.log(`[NOTIFY:${type}]`, message); } catch(e) {}
    if (opts.silent) return;
    alert(message);
  }
}
// --- Auto-sync & reload on reconnect ------------------------------------------------
async function handleOnlineReconnect() {
  console.log('[NETWORK] Detected online status');

  // small delay to let network stabilize
  await new Promise(r => setTimeout(r, 800));

  // attempt to sync offline readings if function exists
  if (typeof syncOfflineReadings === 'function') {
    try {
      showNotify('info', 'Connection restored — syncing offline readings...');
      await syncOfflineReadings();
      showNotify('success', 'Offline readings synced successfully.');
    } catch (err) {
      console.error('[SYNC] Error during automatic sync', err);
      showNotify('error', 'Sync failed. Connect again or check console.');
      // still continue to reload so the page shows current online UI
    }
  } else {
    console.warn('[SYNC] syncOfflineReadings() not found — skipping sync.');
  }

  // reload to reflect online state / fresh data
  // small delay to ensure user sees the toast
  setTimeout(() => {
    console.log('[NETWORK] Reloading page to reflect online state');
    location.reload();
  }, 30000);
}

// register listener once
window.addEventListener('online', handleOnlineReconnect);
// --- Notify user when manual sync is done (if caller uses syncOfflineReadings directly) ---
async function triggerSyncAndNotify() {
  if (typeof syncOfflineReadings !== 'function') {
    showNotify('error', 'Sync routine not available.');
    return;
  }
  showNotify('info', 'Syncing offline readings...');
  try {
    await syncOfflineReadings();
    showNotify('success', 'Offline readings synced.');
  } catch (err) {
    console.error('[MANUAL SYNC]', err);
    showNotify('error', 'Sync failed. See console.');
  }
}

// optional: attach to a button (if you have a sync button)
$(document).off('click', '#syncOfflineNow').on('click', '#syncOfflineNow', triggerSyncAndNotify);

window.addEventListener('offline', async () => {
  await loadOfflineAccounts();
});

/* ===========================================================
   💾 OFFLINE SAVE / SYNC HELPERS
   =========================================================== */
    async function saveOfflineReading(payload) {
        const existing = (await localforage.getItem(STORAGE_KEY)) || [];
        existing.push({ ...payload, synced: false, saved_at: new Date().toISOString() });
        await localforage.setItem(STORAGE_KEY, existing);
        alert('✅ Reading saved locally (offline mode). Will sync automatically.');
    }

    async function syncOfflineReadings() {
    if (!navigator.onLine) return;
    const stored = (await localforage.getItem(STORAGE_KEY)) || [];
    const unsynced = stored.filter(r => !r.synced);
    if (!unsynced.length) return;

    console.log(`[SYNC] Attempting to sync ${unsynced.length} records...`);

    for (const r of unsynced) {
        const payload = {
        account_no: r.account_no,
        present_reading: r.present_reading,
        previous_reading: r.previous_reading,
        consumption: r.consumption,
        reading_month: r.reading_month,
        reference_no: r.reference_no,
        is_high_consumption: r.is_high_consumption || 'no',
        isReRead: r.isReRead || 'false',
        reader_name: '{{ Auth::user()->name ?? "Offline Reader" }}',
        zone: r.zone || 'UNKNOWN',
        property_types_id: r.property_types_id || 1,
        created_at: r.saved_at || new Date().toISOString(),
        from_offline: true
        };

        try {
        const res = await fetch('{{ route("reading.store") }}', {
            method: 'POST',
            headers: {
            'Content-Type': 'application/json',
            'X-CSRF-TOKEN': '{{ csrf_token() }}'
            },
            body: JSON.stringify(payload)
        });

        const text = await res.text();
        let json = {};
        try { json = JSON.parse(text); } catch {
            console.warn('[SYNC] Non-JSON response:', text.slice(0, 200));
            continue;
        }

        if (json.status === 'success') {
            r.synced = true;
            console.log('[SYNC OK]', payload.account_no);
            if (json.redirect_url)
            window.open(json.redirect_url, '_blank', 'width=800,height=800');
        } else {
            console.warn('[SYNC FAIL]', json.message || json);
        }
        } catch (err) {
        console.error('[SYNC ERROR]', err);
        }
    }

    await localforage.setItem(STORAGE_KEY, stored);
    console.log('[SYNC COMPLETE]');
    }

/* AUTO-CACHE every fetchAccountData() call */
(function patchFetchAccountData() {
  const oldFetch = window.fetchAccountData;
  if (typeof oldFetch !== 'function') return;
  window.fetchAccountData = function (...args) {
    const zone = $('#zone_no').val();
    const filter = $('#filter').val();
    const searchBy = $('#search_by').val();
    const search = $('#search').val();
    const params = { zone, filter, search_by: searchBy, search };
    oldFetch.apply(this, args);
    // after small delay, grab DOM data and cache
    setTimeout(() => {
      const accounts = [];
      $('.account-card').each(function () {
        try {
          const acc = $(this).data('account');
          if (acc) accounts.push(acc);
        } catch {}
      });
      if (accounts.length > 0) {
        localforage.setItem(MASTER_KEY, { accounts });
        console.log(`[AUTO-CACHE] Saved ${accounts.length} accounts for offline use.`);
      }
    }, 1200);
  };
})();

/* ===========================================================
   📂 LOAD CACHED DATA WHEN OFFLINE
   =========================================================== */
async function loadOfflineAccounts() {
  console.log('[OFFLINE] Loading cached accounts...');
  const data = await localforage.getItem(MASTER_KEY);
  const container = document.querySelector('.concessionaire-list');
  const result = document.querySelector('.concessionaire-result');

  if (!data || !data.accounts?.length) {
    container.innerHTML = `<div class="alert alert-warning text-center">⚠️ No offline data found. Please go online and refresh.</div>`;
    return;
  }

  result.innerHTML = `<div class="text-uppercase fw-bold text-muted mb-2">Accounts Found: ${data.accounts.length}</div>`;
  container.innerHTML = '';

  data.accounts.forEach(acc => {
    const html = `
      <div class="card shadow mb-3 account-card"
           data-account='${JSON.stringify(acc)}'
           style="cursor:pointer;position:relative;background:#fff;">
        <div class="card-body">
          <div style="width:12px;height:12px;border-radius:50%;position:absolute;top:18px;right:25px;background:#28a745;"></div>
          <h5 class="card-title mb-0 fw-normal">Account No: ${acc.account_no}</h5>
          <hr class="my-2 mb-2">
          <h5 class="fw-normal">Meter No: ${acc.meter_serial_no || '-'}</h5>
          <h4>${(acc.name || acc.user?.name || 'N/A').toUpperCase()}</h4>
          <h5 class="fw-normal text-capitalize">${acc.address || ''}</h5>
          <div class="mt-2 small text-muted fw-bold text-uppercase">ACTIVE</div>
        </div>
      </div>`;
    container.insertAdjacentHTML('beforeend', html);
  });
}

/* ===========================================================
   🔍 OFFLINE SEARCH HANDLER (MATCHES REAL CARD LAYOUT)
   =========================================================== */
$(document).on('input', '#search', async function () {
  const query = $(this).val().trim().toLowerCase();
  const searchBy = $('#search_by').val() || 'name';
  const zone = $('#zone').val();
  var cons_result = $('.concessionaire-result div').text();

  if (navigator.onLine) return; // Skip if online (handled by API)

  console.log('[OFFLINE SEARCH] Searching offline cache for:', query);

  const offlineAccounts = await localforage.getItem('offline_accounts') || [];
  const accounts = offlineAccounts.accounts || offlineAccounts; // handle either format

  if (!accounts?.length) {
    console.warn('[OFFLINE SEARCH] No cached accounts found.');
    return $('#accountsContainer').html(`
      <p class="text-center text-muted py-4">⚠️ No offline data available. Please go online and refresh.</p>
    `);
  }

  const results = accounts.filter(acc => {
    const matchZone = zone === 'ALL' || !zone || acc.zone === zone;
    if (!matchZone) return false;

    if (!query) return true; // show all if empty

    const searchField =
      searchBy === 'account_no'
        ? acc.account_no
        : searchBy === 'address'
        ? acc.address
        : acc.name;

    return searchField?.toLowerCase().includes(query);
  });

  cons_result = results.length;

  console.log(`[OFFLINE SEARCH] Found ${results.length} matches.`);
  renderOfflineResults(results);
});

/* ===========================================================
   🧩 RENDER OFFLINE RESULTS (MATCHES ORIGINAL CARD DESIGN)
   =========================================================== */
function renderOfflineResults(accounts) {
  const container = $('.concessionaire-list');
  container.empty();

  // Update result count dynamically
  const resultText = `${accounts.length} Concessionaire${accounts.length !== 1 ? 's' : ''} Found`;
  $('.concessionaire-result div').text(resultText);
  if (!accounts.length) {
    container.html('<p class="text-center text-muted py-4">No accounts found.</p>');
    return;
  }

  accounts.slice(0, 100).forEach(acc => {
    const zoneColor = '#6c757d'; // gray default, could change if you have zone-color mapping

    container.append(`
      <div class="card mb-2 account-card"
           data-account='${JSON.stringify(acc)}'
           data-account-no="${acc.account_no}">
        <div class="card-body position-relative">

          <div style="
            width: 12px;
            height: 12px;
            border-radius: 50%;
            position: absolute;
            top: 18px;
            right: 25px;
            background-color: ${zoneColor};
          " title="${acc.zone || 'UNKNOWN'}"></div>

          <h5 class="card-title mb-0 fw-normal">Account No: ${acc.account_no}</h5>
          <hr class="my-2 mb-2">
          <h5 class="fw-normal">Meter No: ${acc.meter_serial_no || '-'}</h5>
          <h4>${acc.name || 'N/A'}</h4>
          <h5 class="fw-normal text-capitalize">${acc.address || '-'}</h5>
          <div class="mt-2 small text-muted fw-bold text-uppercase">${acc.zone || 'UNKNOWN'}</div>
        </div>
      </div>
    `);
  });

  console.log('[OFFLINE SEARCH] Rendered', accounts.length, 'offline results.');
}
/* ===========================================================
   🧠 OFFLINE CLICK HANDLER (Final Clean Version)
   =========================================================== */
$(document).on('click', '.account-card', async function () {
  if (navigator.onLine) {
    console.log('[OFFLINE] User is online — skipping offline handler.');
    return;
  }

  console.log('[OFFLINE] Account card clicked — starting offline flow.');

  try {
    // Load cached offline data (array of accounts)
    const cachedAccounts = await localforage.getItem('offline_accounts');
    console.log(`[DEBUG] Loaded ${cachedAccounts?.length || 0} offline accounts from cache.`);

    if (!cachedAccounts || !cachedAccounts.length) {
      alert('❌ No offline data found. Please go online and refresh.');
      console.warn('[DEBUG] offline_accounts not found or empty.');
      return;
    }

    // Get account number from the clicked card
    const accountDataAttr = $(this).data('account');
    const accountNo =
      accountDataAttr?.account_no ||
      $(this).attr('data-account-no') ||
      null;

    if (!accountNo) {
      alert('⚠️ Account number missing — cannot open offline modal.');
      console.warn('[DEBUG] Missing account_no on clicked element.');
      return;
    }

    console.log('[DEBUG] Looking for account:', accountNo);

    // Find matching account in offline data
    const account = cachedAccounts.find(a => a.account_no === accountNo);

    if (!account) {
      alert('❌ Account not found in offline data.');
      console.warn('[DEBUG] Account not found. First few cached:', cachedAccounts.slice(0, 3));
      return;
    }

    const prevReading = Number(account.previous_reading ?? 0);
    console.log('[DEBUG] Found account:', account);
    console.log('[DEBUG] Previous reading:', prevReading);

    openOfflineModal(account, prevReading);

  } catch (err) {
    console.error('[OFFLINE] Error handling offline account:', err);
    alert('⚠️ Something went wrong loading offline data.');
  }
});

/* ===========================================================
   📄 MODAL BUILDER
   =========================================================== */
function openOfflineModal(account, prevReading = 0) {
  const today = new Date().toISOString().split('T')[0];

  const modalContent = `
    <p class="offline-accountNo"><strong>Account No:</strong> ${account.account_no}</p>
    <p><strong>Name:</strong> <span class="offline-name">${account.name || 'N/A'}</span></p>
    <p ><strong>Address:</strong> <span class="offline-address">${account.address || 'N/A'}</span></p>
    <hr>

    <div class="row mt-3">
      <div class="col-md-12 mb-3">
        <label class="form-label">Reading Month</label>
        <input type="date" class="form-control h-extend" id="reading_month" value="${today}">
      </div>

      <div class="col-md-6 mb-3">
        <label class="form-label">Previous Reading</label>
        <input type="number" class="form-control h-extend" id="previous_reading" value="${prevReading}" readonly>
      </div>

      <div class="col-md-6 mb-3">
        <label class="form-label">Present Reading</label>
        <input type="number" class="form-control h-extend" id="present_reading" placeholder="Enter reading">
      </div>

      <div class="col-md-6 mb-3">
        <label class="form-label">Consumption</label>
        <input type="text" class="form-control h-extend" id="consumption" value="0" readonly>
      </div>
    </div>

    <div class="text-end mt-4">
      <button type="button" class="btn btn-primary px-5 py-3 text-uppercase fw-bold" id="proceedOffline">
        Save Offline
      </button>
    </div>
  `;

  $('#accountModalBody').html(modalContent);
  $('#accountModal').modal('show');

  // Live consumption calculator
  $(document).off('input', '#present_reading').on('input', '#present_reading', function () {
    const present = Number($(this).val()) || 0;
    const previous = Number($('#previous_reading').val()) || 0;
    const consumption = Math.max(present - previous, 0);
    $('#consumption').val(consumption);
  });
}
/* Save offline */
$(document).off('click', '#proceedOffline').on('click', '#proceedOffline', async function () {
  const present = parseFloat($('#present_reading').val()) || 0;
  const previous = parseFloat($('#previous_reading').val()) || 0;
  const month = $('#reading_month').val();
  const consumption = Math.max(present - previous, 0);
  const adminId = '{{ Auth::id() ?? 0 }}';
  const refNo = `NST-SRWD-0${adminId}-${Date.now()}`;
  const name = $('.offline-name').text() || '';
  const address = $('.offline-address').text() || '';

  if (!selectedAccountNo) return alert('No account selected.');
  if (present <= previous) return alert('Present reading must be greater than previous.');


  const payload = {
    account_no: selectedAccountNo,
    name: name,
    address: address,
    reading_month: month,
    previous_reading: previous,
    present_reading: present,
    consumption,
    is_high_consumption: 'no',
    isReRead: 'false',
    reference_no: refNo,
    synced: false,
    saved_at: new Date().toISOString()
  };

  await saveOfflineReading(payload);
  $('#accountModal').modal('hide');

  // Show offline SOA preview immediately
  console.log('[OFFLINE SOA] Opening preview for', payload);
  openOfflineSOA(payload);
});

/* ===========================================================
   🧾 OFFLINE SOA PREVIEW
   =========================================================== */
   async function openOfflineSOA(data) {
    console.log('[OFFLINE SOA] Generating SOA for:', data);
    const today = new Date();
    const readingDate = new Date(data.reading_month);
    const billDate = today.toLocaleDateString('en-PH');

    // Calculate disconnection and penalty dates
    const disconnectionDate = new Date(readingDate);
    disconnectionDate.setDate(disconnectionDate.getDate() + 15);
    const penaltyDate = new Date(disconnectionDate);
    penaltyDate.setDate(penaltyDate.getDate() + 1);

    const refNo = data.reference_no || 'NST-SRWD-00' + Date.now();
    const logoUrl = '{{ asset(config('app.client_logo')) }}';
    const qrUrl = '{{ asset("images/srwd_qr.png") }}';
    const orgName = @json(config('app.org_name'));
    const orgAddress = @json(config('app.org_address'));
    const orgContact1 = @json(config('app.org_contact_line1'));
    const orgContact2 = @json(config('app.org_contact_line2'));
    const reader = '{{ Auth::user()->name ?? "Offline Reader" }}';
    // 💧 Compute Basic Charge & Billing Amounts
    const offlineMasters = await localforage.getItem('offline_master_data');
    console.log('[OFFLINE SOA] Loaded offline accounts for rate lookup:', offlineMasters?.length || 0);
    const rates = offlineMasters?.rates || [];
    console.log('[OFFLINE SOA] Loaded rates for calculation:', rates.length || 0);
    const propertyTypeId = data.property_types_id || 1;
    console.log('[OFFLINE SOA] Using property type ID:', propertyTypeId);
    const consumption = Number(data.consumption || 0);
    console.log('[OFFLINE SOA] Consumption for calculation:', consumption);
    // Find the matching rate for the property type and consumption
    let matchedRate = rates
    .filter(r => r.property_types_id == propertyTypeId && r.cu_m <= consumption)
    .sort((a, b) => b.cu_m - a.cu_m)[0];

    // If no match, fallback to highest available for that property type
    if (!matchedRate) {
    matchedRate = rates
        .filter(r => r.property_types_id == propertyTypeId)
        .sort((a, b) => b.cu_m - a.cu_m)[0];
    }

    const basicCharge = matchedRate ? Number(matchedRate.amount) : 0;
    const amountDue = basicCharge; // for now assume no discounts/unpaid
    const penaltyAmt = amountDue * 0.10;
    const amountAfterDue = amountDue + penaltyAmt;

    // 🧾 For template rendering
    const formatPeso = v => Number(v).toLocaleString('en-PH', { minimumFractionDigits: 2 });

    const html = `
    <!DOCTYPE html>
    <html lang="en">
    <head>
        <meta charset="UTF-8" />
        <meta name="viewport" content="width=device-width, initial-scale=1.0" />
        <title>Offline SOA ${refNo}</title>
        <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.5/dist/css/bootstrap.min.css" rel="stylesheet" />
        <style>
        body { font-family: Verdana, Geneva, Tahoma, sans-serif; font-size: 12px; background: #fff; }
        .soa-wrapper {
            position: relative;
            max-width: 450px;
            margin: 20px auto;
            padding: 25px;
            background: white;
            border-radius: 5px;
            box-shadow: 0 0 10px rgba(0,0,0,0.15);
        }
        .header { text-align:center; }
        .header img { width:90px; margin-bottom:8px; }
        .header p { margin:0; font-size:12px; text-transform:uppercase; }
        .title { text-align:center; font-weight:700; font-size:18px; margin:10px 0; text-transform:uppercase; }
        .divider { border-bottom:1px dashed #000; margin:8px 0; }
        .row-line { display:flex; justify-content:space-between; margin:2px 0; text-transform:uppercase; font-size:13px; }
        .highlight { font-weight:800; font-size:18px; }
        .offline-badge {
            position:absolute; top:10px; right:10px;
            border:1px solid red; color:red; font-weight:700;
            padding:3px 8px; border-radius:3px; font-size:10px;
            text-transform:uppercase;
        }
        .watermark {
            position:fixed; top:45%; left:0; right:0; text-align:center;
            opacity:0.08; font-size:60px; transform:rotate(-25deg);
            pointer-events:none;
        }
        .emp {
            background:#000; color:#fff;
            text-align:center; padding:6px;
            font-size:12px; font-weight:600;
            text-transform:uppercase;
        }
        @media print {
            .no-print { display:none !important; }
        }
        </style>
    </head>
    <body>
        <div class="watermark">OFFLINE COPY</div>

        <div class="text-center mb-3 no-print">
        <button class="btn btn-primary btn-sm me-2" onclick="window.print()">Print</button>
        <button class="btn btn-secondary btn-sm" onclick="window.close()">Close</button>
        </div>

        <div class="soa-wrapper">
        <div class="offline-badge">Offline</div>

        <div class="header">
            <img src="${logoUrl}" alt="Logo">
            <p>Republic of the Philippines</p>
            <p style="font-size:15px;font-weight:700;">${orgName}</p>
            <p>${orgAddress}</p>
            <p>${orgContact1}</p>
            <p>${orgContact2}</p>
        </div>

        <div class="title">Statement of Account</div>
        <div class="divider"></div>

        <div style="text-transform:uppercase;">
            <div class="row-line"><div><strong>Account No.</strong></div><div><strong>${data.account_no}</strong></div></div>
            <div class="row-line"><div><strong>NAME: ${data.name || 'N/A'}</strong></div></div>
            <div class="row-line"><div>ADDRESS: ${data.address || 'N/A'}</div></div>
            <div class="row-line"><div>Meter No:</div><div>${data.meter_serial_no || '---'}</div></div>
        </div>

        <div class="divider"></div>
        <div class="text-center fw-bold" style="margin:5px 0;">CURRENT BILLING INFO</div>
        <div class="divider"></div>

        <div>
            <div class="row-line"><div>Bill Date</div><div>11/04/2025</div></div>
            <div class="row-line"><div>Period</div><div>10/03/2025 TO 11/04/2025</div></div>
            <div class="row-line"><div>Due Date</div><div></div>11/18/2025</div>
            <div class="row-line"><div>Disconnection Date</div><div>11/25/2025</div></div>
        </div>

        <div class="divider"></div>
        <div>
            <div class="row-line"><div>Previous Reading</div><div>${data.previous_reading}</div></div>
            <div class="row-line"><div>Present Reading</div><div>${data.present_reading}</div></div>
            <div class="row-line highlight"><div>Cub. M Used</div><div>${data.consumption}</div></div>
        </div>

        <div class="divider"></div>
        <div class="row-line"><div>Basic Charge</div><div>${formatPeso(basicCharge)}</div></div>
        <div class="row-line highlight"><div>Current Billing:</div><div>${formatPeso(amountDue)}</div></div>
        <div class="divider"></div>
        <div class="row-line highlight"><div>Amount Due:</div><div>${formatPeso(amountDue)}</div></div>

        <div class="divider"></div>
        <div class="row-line"><div>Payment After Due Date</div><div></div></div>
        <div class="row-line"><div>Penalty Date</div><div>11/19/2025</div></div>
        <div class="row-line"><div>Penalty Amt</div><div>${formatPeso(penaltyAmt)}</div></div>
        <div class="row-line highlight"><div>Amount After Due:</div><div>${formatPeso(amountAfterDue)}</div></div>

        <div class="divider"></div>
        <div class="text-center fw-bold" style="margin:5px 0;">6 Months Consumption History</div>
        <div class="d-flex justify-content-between text-center" style="font-size:12px;">
            <div>SEP<br>NA</div><div>AUG<br>NA</div><div>JUL<br>NA</div><div>JUN<br>NA</div><div>MAY<br>NA</div><div>APR<br>NA</div>
        </div>

        <div class="divider"></div>
        <p class="text-center fw-bold" style="font-size:12px;">
            Two (2) months of non-payment of bills mean AUTOMATIC DISCONNECTION
        </p>

        <div class="divider"></div>
        <div class="row-line"><div>Bill No:</div><div>${refNo}</div></div>
        <div class="row-line"><div>Meter Reader</div><div>${reader}</div></div>
        <div class="row-line"><div>Time Stamp:</div><div>${today.toString()}</div></div>

        <div class="divider"></div>
        <div class="text-center mt-2">
            <img src="${qrUrl}" alt="QR" width="90" /><br>
            <h6 style="font-weight:bold;text-transform:uppercase;">Pay Now</h6>
            <ol style="font-size:10px;text-transform:uppercase;display:inline-block;text-align:left;margin:0;padding-left:18px;">
            <li>Scan the QR code.</li>
            <li>Login to your account.</li>
            <li>Pay the total amount due.</li>
            <li>Keep your receipt.</li>
            </ol>
        </div>

        <div class="divider"></div>
        <div class="emp">This is NOT valid as Official Receipt</div>
        </div>
    </body>
    </html>
    `;

    const w = window.open('', '_blank');
    w.document.write(html);
    w.document.title = `Offline SOA ${refNo}`;
    w.document.close();
    setTimeout(() => w.print(), 600);
    }
async function selectOfflineAccount(accountNo) {
  const previousCache = (await localforage.getItem('offline_previous')) || {};
  const prevReading = previousCache[accountNo] ?? 0;

  $('#previous_reading').val(prevReading);
  selectedAccountNo = accountNo;

  console.log(`[OFFLINE] Prefilled previous reading for ${accountNo}: ${prevReading}`);
}
</script>
<script>
if ('serviceWorker' in navigator) {
  window.addEventListener('load', () => {
    navigator.serviceWorker.register('/serviceworker.js')
      .then(reg => console.log('[SW] Registered ✅', reg))
      .catch(err => console.error('[SW] Failed ❌', err));
  });
}
</script>
@endsection
