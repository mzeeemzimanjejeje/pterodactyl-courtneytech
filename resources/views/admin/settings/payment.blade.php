@include('partials.admin.settings.nav', ['activeTab' => 'payment'])

@section('title')
    Payment API Keys
@endsection

@section('content-header')
    <h1>Payment API Keys<small>Configure the payment gateways used for wallet top-ups.</small></h1>
    <ol class="breadcrumb">
        <li><a href="{{ route('admin.index') }}">Admin</a></li>
        <li><a href="{{ route('admin.settings') }}">Settings</a></li>
        <li class="active">Payment API Keys</li>
    </ol>
@endsection

@section('content')
    @yield('settings::nav')
    <div class="row">
        <div class="col-xs-12">
            <div class="callout callout-warning">
                <h4>Keep payment credentials private</h4>
                <p>Only administrators can access this page. Existing credentials are never displayed. Leave a field blank to keep its current value.</p>
            </div>
        </div>
    </div>
    <form action="{{ route('admin.settings.payment') }}" method="POST">
        {!! csrf_field() !!}
        <input type="hidden" name="_method" value="PATCH">
        <div class="row">
            <div class="col-md-6">
                <div class="box">
                    <div class="box-header with-border">
                        <h3 class="box-title">Paystack — Card Payments</h3>
                    </div>
                    <div class="box-body">
                        <div class="form-group">
                            <label class="control-label" for="paystack_public_key">Public Key</label>
                            <input id="paystack_public_key" type="text" class="form-control" name="paystack_public_key" autocomplete="off" placeholder="{{ $configured['settings::paystack:public_key'] ? 'Configured — enter a new value to replace it' : 'pk_live_...' }}">
                        </div>
                        <div class="form-group">
                            <label class="control-label" for="paystack_secret_key">Secret Key</label>
                            <input id="paystack_secret_key" type="password" class="form-control" name="paystack_secret_key" autocomplete="new-password" placeholder="{{ $configured['settings::paystack:secret_key'] ? 'Configured — enter a new value to replace it' : 'sk_live_...' }}">
                        </div>
                    </div>
                </div>
            </div>
            <div class="col-md-6">
                <div class="box">
                    <div class="box-header with-border">
                        <h3 class="box-title">CourtneyTech — M-Pesa</h3>
                    </div>
                    <div class="box-body">
                        <div class="form-group">
                            <label class="control-label" for="courtney_base_url">API Base URL</label>
                            <input id="courtney_base_url" type="url" class="form-control" name="courtney_base_url" value="{{ old('courtney_base_url') }}" placeholder="https://courtneytech.xyz/api">
                        </div>
                        <div class="form-group">
                            <label class="control-label" for="courtney_api_key">API Key</label>
                            <input id="courtney_api_key" type="text" class="form-control" name="courtney_api_key" autocomplete="off" placeholder="{{ $configured['settings::courtneytech:api_key'] ? 'Configured — enter a new value to replace it' : 'CourtneyTech API key' }}">
                        </div>
                        <div class="form-group">
                            <label class="control-label" for="courtney_api_secret">API Secret</label>
                            <input id="courtney_api_secret" type="password" class="form-control" name="courtney_api_secret" autocomplete="new-password" placeholder="{{ $configured['settings::courtneytech:api_secret'] ? 'Configured — enter a new value to replace it' : 'CourtneyTech API secret' }}">
                        </div>
                        <div class="form-group">
                            <label class="control-label" for="courtney_account_id">Payment Account ID</label>
                            <input id="courtney_account_id" type="text" class="form-control" name="courtney_account_id" autocomplete="off" placeholder="{{ $configured['settings::courtneytech:account_id'] ? 'Configured — enter a new value to replace it' : 'e.g. 9' }}">
                        </div>
                    </div>
                </div>
            </div>
        </div>
        <div class="box-footer">
            <button type="submit" class="btn btn-primary pull-right">Update Payment Settings</button>
        </div>
    </form>
@endsection
