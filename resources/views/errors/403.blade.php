@extends('errors.minimal')

@section('title', __('Access Denied'))
@section('code', '403')
@section('message', __($exception->getMessage() ?: 'You do not have permission to access this resource.'))
