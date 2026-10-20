@extends('layouts.admin')

@section('title')
@lang('Api Deposits')
@endsection

@section('breadcrumb')
<section class="section">
    <div class="section-header">
        <h1>@lang('Api Deposits')</h1>
    </div>
</section>
@endsection

@section('content')

<div class="row">
    <div class="col-12 col-md-12 col-lg-12">
        <div class="card">
            <div class="card-header d-flex justify-content-end">
                <form action="" class="d-flex flex-wrap justify-content-end">
                    <div class="form-group m-1 flex-grow-1">
                        <select class="form-control" onChange="window.location.href=this.value">
                            <option value="{{filter('status','')}}">@lang('All Deposits')</option>
                            <option value="{{filter('status',0)}}" {{request('status')==0 ?'selected':''}}>
                                @lang('Pending Deposits')</option>
                            <option value="{{filter('status',1)}}" {{request('status')==1 ?'selected':''}}>
                                @lang('Completed Deposits')</option>
                            <option value="{{filter('status',2)}}" {{request('status')==2 ?'selected':''}}>
                                @lang('Rejected Deposits')</option>
                        </select>
                    </div>

                    <div class="form-group m-1 flex-grow-1">
                        <div class="input-group">
                            <input type="text" class="form-control" name="search" value="{{$search ?? ''}}"
                                placeholder="@lang('Transaction ID')">
                            <div class="input-group-append">
                                <button class="input-group-text btn btn-primary text-white" id="my-addon"><i
                                        class="fas fa-search"></i></button>
                            </div>
                        </div>
                    </div>
                </form>
            </div>
            <div class="card-body text-center">
                <div class="table-responsive">
                    <table class="table table-striped">
                        <tr>
                            <th>@lang('Transaction ID')</th>
                            <th>@lang('Merchant')</th>
                            <th>@lang('Amount(With Charge)')</th>
                            <th>@lang('Method')</th>
                            <th>@lang('Status')</th>
                            <th>@lang('Details')</th>
                            <th>@lang('Action')</th>
                        </tr>
                        @forelse ($deposits as $info)
                        <tr>
                            <td data-label="@lang('Transaction ID')">
                                {{$info->txn_id}}
                            </td>
                            <td data-label="@lang('User')">{{$info->merchant->email}}</td>
                            <td data-label="@lang('Amount')">{{amount($info->amount,$info->currency->type,2)}}
                                {{$info->currency->code}}</td>

                            <td data-label="@lang('Method')">{{$info->method}}</td>
                            <td data-label="@lang('Status')">
                                @if ($info->status == 0)
                                <span class="badge badge-warning"> {{__('Pending')}} </span>
                                @elseif($info->status == 2)
                                <span class="badge badge-danger"> {{__('Rejected')}} </span>
                                @else
                                <span class="badge badge-success"> {{__('Completed')}} </span>
                                @endif
                            </td>

                            <td data-label="@lang('Details')">
                                <small>{{$info->txn_details ?? 'N/A'}}</small>
                            </td>

                            <td data-label="@lang('Action')">
                                <div
                                    class="d-flex flex-wrap flex-lg-nowrap align-items-center justify-content-end justify-content-lg-center">
                                    @if ($info->status == 0)
                                    <a href="javascript:void()" class="btn btn-primary approve btn-sm m-1"
                                        data-id="{{$info->id}}" data-toggle="tooltip" title="@lang('Approve')"><i
                                            class="fas fa-check"></i></a>

                                    <a href="javascript:void()" class="btn btn-danger reject btn-sm m-1"
                                        data-id="{{$info->id}}" data-toggle="tooltip" title="@lang('Reject')"><i
                                            class="fas fa-ban"></i></a>
                                    @else
                                    <a href="javascript:void()" class="btn btn-primary disabled btn-sm m-1"><i
                                            class="fas fa-check"></i></a>

                                    <a href="javascript:void()" class="btn btn-danger disabled btn-sm m-1"><i
                                            class="fas fa-ban"></i></a>
                                    @endif
                                </div>
                            </td>
                        </tr>
                        @empty

                        <tr>
                            <td class="text-center" colspan="100%">@lang('No Data Found')</td>
                        </tr>

                        @endforelse
                    </table>
                </div>
            </div>
            @if ($deposits->hasPages())
            {{ $deposits->links() }}
            @endif
        </div>
    </div>
</div>

<div id="approveModal" class="modal fade" tabindex="-1" role="dialog" aria-hidden="true">
    <div class="modal-dialog" role="document">
        <form action="{{route('admin.api.approve.deposit')}}" method="POST">
            @csrf
            <input type="hidden" name="id">
            <div class="modal-content">
                <div class="modal-body">
                    <h6 class="mt-3">@lang('Are you sure to approve?')</h6>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-dark" data-dismiss="modal">@lang('Close')</button>
                    <button type="submit" class="btn btn-primary">@lang('Confirm')</button>
                </div>
            </div>
        </form>
    </div>
</div>
<div id="rejectModal" class="modal fade" tabindex="-1" role="dialog" aria-hidden="true">
    <div class="modal-dialog" role="document">
        <form action="{{route('admin.api.reject.deposit')}}" method="POST">
            @csrf
            <input type="hidden" name="id">
            <div class="modal-content">
                <div class="modal-body">
                    <h6 class="mt-3">@lang('Are you sure to reject?')</h6>

                    <div class="form-group">
                        <label>@lang('Reject Reasons')</label>
                        <textarea name="reject_reason" class="form-control mt-2" rows="5"></textarea>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-dark" data-dismiss="modal">@lang('Close')</button>
                    <button type="submit" class="btn btn-danger">@lang('Confirm')</button>
                </div>
            </div>
        </form>
    </div>
</div>
@endsection

@push('script')
<script>
    'use strict';
        $('.approve').on('click',function () { 
            $('#approveModal').find('input[name=id]').val($(this).data('id'))
            $('#approveModal').modal('show')
        })
        $('.reject').on('click',function () { 
            $('#rejectModal').find('input[name=id]').val($(this).data('id'))
            $('#rejectModal').modal('show')
        })
</script>
@endpush