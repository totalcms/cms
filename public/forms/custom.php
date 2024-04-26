<?php include __DIR__ . '/_start.php'; ?>

	<h1>Total CMS Custom Form Demo</h1>

	{% import "totalform.twig" as form %}

	{{ form.start("custom", { method:"post" }) }}

		{{ form.id('id', { autogen:"${text}" }) }}
		{{ form.text('text') }}

		{{ form.textarea('textarea') }}
		{{ form.checkbox('checkbox') }}
		{{ form.number('number') }}
		{{ form.toggle('toggle') }}
		{{ form.color('color') }}
		{{ form.date('date') }}
		{{ form.datetime('datetime') }}
		{{ form.time('time') }}
		{{ form.email('email') }}
		{{ form.phone('phone') }}
		{{ form.url('url') }}
		{{ form.password('password') }}

		{% set options = [
			{value:"dog",     label:"Dog"},
			{value:"cat",     label:"Cat"},
			{value:"hamster", label:"Hamster"},
			{value:"parrot",  label:"Parrot"},
			{value:"spider",  label:"Spider"},
			{value:"goldfish",label:"Goldfish"},
		] %}

		{{ form.select('select', options) }}
		{{ form.multiselect('multiselect', options) }}
		{{ form.list('list', options) }}
		{{ form.rangeslider('range') }}

		{{ form.styledtext('styledtext') }}
		{{ form.svg('svg') }}
		{{ form.image('image') }}

	{{ form.end() }}

<?php include __DIR__ . '/_end.php'; ?>
