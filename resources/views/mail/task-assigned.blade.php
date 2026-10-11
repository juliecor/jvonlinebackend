<x-mail::message>
# {{ $task->priority === 'urgent' ? 'An urgent task for you' : 'A new task for you' }}

Hi {{ \Illuminate\Support\Str::before($assignee->name, ' ') }}, {{ $task->creator?->name ?? 'your admin' }} at {{ $task->realty->name }} gave you a task:

**{{ $task->title }}**

@if ($task->due_on)
Due **{{ $task->due_on->format('M j, Y') }}**.
@endif
@if ($task->offer)
About the offer for {{ $task->offer->buyer_name }} ({{ $task->offer->code }}).
@elseif ($task->project)
About {{ $task->project->name }}.
@endif

@if ($task->description)
> {{ \Illuminate\Support\Str::limit($task->description, 600) }}
@endif

<x-mail::button :url="$url">
Open the task
</x-mail::button>

Move it to In progress and Done on your Tasks board, and add comments or files there.

jvconline
</x-mail::message>
