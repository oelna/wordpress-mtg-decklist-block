(function(blocks, element, blockEditor, components, i18n){
	var el = element.createElement;
	var useBlockProps = blockEditor.useBlockProps;
	var InspectorControls = blockEditor.InspectorControls;

	function uuid(){
		if (window.crypto && crypto.randomUUID) {
			return crypto.randomUUID();
		}
		return 'mtgdl-' + Math.random().toString(36).slice(2) + Date.now().toString(36);
	}

	blocks.registerBlockType('mtg/decklist', {
		apiVersion: 2,
		title: i18n.__('MTG Decklist', 'mtgdl'),
		description: i18n.__('Paste a Magic: The Gathering decklist and render it as a linked table with grouping.', 'mtgdl'),
		icon: 'editor-table',
		category: 'widgets',
		attributes: {
			content: { type: 'string', default: '' },
			instanceId: { type: 'string', default: '' },
			styleVariant: { type: 'string', default: 'A' },
			grouping: { type: 'string', default: 'alpha' }
		},
		edit: function(props){
			var blockProps = useBlockProps({ className: 'mtgdl-editor' });

			if (!props.attributes.instanceId) {
				props.setAttributes({ instanceId: uuid() });
			}

			return el('div', blockProps,
				el(InspectorControls, {},
					el(components.PanelBody, { title: i18n.__('Decklist Display', 'mtgdl'), initialOpen: true },
						el(components.SelectControl, {
							label: i18n.__('Style', 'mtgdl'),
							value: props.attributes.styleVariant,
							options: [
								{ label: 'A', value: 'A' },
								{ label: 'B', value: 'B' },
								{ label: 'C', value: 'C' }
							],
							onChange: function(value){
								props.setAttributes({ styleVariant: value });
							}
						}),
						el(components.SelectControl, {
							label: i18n.__('Grouping', 'mtgdl'),
							value: props.attributes.grouping,
							options: [
								{ label: i18n.__('Alphabetical', 'mtgdl'), value: 'alpha' },
								{ label: i18n.__('Mana value', 'mtgdl'), value: 'mana' },
								{ label: i18n.__('Color identity', 'mtgdl'), value: 'color' }
							],
							help: i18n.__('Grouping uses Scryfall data saved on post save; if missing, falls back gracefully.', 'mtgdl'),
							onChange: function(value){
								props.setAttributes({ grouping: value });
							}
						})
					)
				),
				el(components.TextareaControl, {
					label: i18n.__('Decklist', 'mtgdl'),
					help: i18n.__('Paste a decklist (Moxfield, Arena, MTGO, or plain text). Use "SIDEBOARD:" to start the sideboard section.', 'mtgdl'),
					value: props.attributes.content,
					onChange: function(value){
						props.setAttributes({ content: value });
					},
					rows: 16
				})
			);
		},
		save: function(){
			return null;
		}
	});
})(window.wp.blocks, window.wp.element, window.wp.blockEditor, window.wp.components, window.wp.i18n);
