@extends('errors.layout')

@section('code', '429')
@section('title', 'Too many requests')
@section('message', $retryMessage ?? 'Slow down for a moment, then try again.')
