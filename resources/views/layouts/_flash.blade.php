{{--
    What the last action did, and what went wrong with a form, for toast.js to show as toasts at the top right, as
    AMV's toast(status, message). A controller says what happened with ->with('message', …), and how it went with
    ->with('status', 'info'), 'warning' or 'error' (success if it doesn't say). A form's errors make an error toast
    with the first of them; each is also shown by its field. Without JavaScript these boxes show on the page instead.
--}}
@php
    $flashes = [];

    if (session()->has('message')) {
        $status = session('status', 'success');
        $flashes[] = [in_array($status, ['success', 'error', 'warning', 'info'], true) ? $status : 'info', session('message')];
    }

    if ($errors->any()) {
        $more = $errors->count() - 1;
        $flashes[] = ['error', $errors->first().($more > 0 ? ' And '.$more.' more to fix below.' : '')];
    }
@endphp
@foreach ($flashes as [$status, $message])
    <div class="alert alert-{{ $status }} flash" role="{{ $status === 'error' ? 'alert' : 'status' }}" data-toast="{{ $status }}">{{ $message }}</div>
@endforeach
