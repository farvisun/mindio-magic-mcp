( function () {
	'use strict';

	const root = document.querySelector( '[data-fmp-admin]' );
	if ( ! root ) {
		return;
	}

	const config = window.MindioMagicMCPAdmin || {};
	const liveRegion = document.getElementById( 'fmp-copy-status' );

	const announce = ( message ) => {
		if ( ! liveRegion ) {
			return;
		}
		liveRegion.textContent = '';
		window.setTimeout( () => {
			liveRegion.textContent = message;
		}, 20 );
	};

	const targetValue = ( target ) => {
		if ( 'value' in target ) {
			return target.value;
		}
		return target.textContent.trim();
	};

	const legacyCopy = ( value ) => {
		const helper = document.createElement( 'textarea' );
		helper.value = value;
		helper.setAttribute( 'readonly', '' );
		helper.style.position = 'fixed';
		helper.style.opacity = '0';
		document.body.appendChild( helper );
		helper.select();

		let copied = false;
		try {
			copied = document.execCommand( 'copy' );
		} catch ( error ) {
			copied = false;
		}
		helper.remove();
		return copied;
	};

	const copyValue = async ( value ) => {
		if ( navigator.clipboard && window.isSecureContext ) {
			try {
				await navigator.clipboard.writeText( value );
				return true;
			} catch ( error ) {
				return legacyCopy( value );
			}
		}
		return legacyCopy( value );
	};

	root.querySelectorAll( '[data-copy-target]' ).forEach( ( button ) => {
		button.addEventListener( 'click', async () => {
			const target = document.getElementById( button.dataset.copyTarget );
			if ( ! target ) {
				return;
			}

			const label = button.querySelector( '.fmp-copy-button__label' );
			const originalLabel = label ? label.textContent : '';
			const originalAria = button.getAttribute( 'aria-label' ) || '';
			const copied = await copyValue( targetValue( target ) );

			if ( ! copied ) {
				announce( config.copyFailed || 'Copy failed.' );
				if ( 'select' in target ) {
					target.focus();
					target.select();
				}
				return;
			}

			button.classList.add( 'is-copied' );
			if ( label ) {
				label.textContent = config.copied || 'Copied';
			}
			button.setAttribute( 'aria-label', config.copied || 'Copied' );
			announce( config.copied || 'Copied' );

			window.setTimeout( () => {
				button.classList.remove( 'is-copied' );
				if ( label ) {
					label.textContent = originalLabel || config.copy || 'Copy';
				}
				button.setAttribute( 'aria-label', originalAria );
			}, 1800 );
		} );
	} );

	root.querySelectorAll( 'input[readonly]' ).forEach( ( input ) => {
		input.addEventListener( 'click', () => input.select() );
	} );

	root.querySelectorAll( 'form[data-confirm]' ).forEach( ( form ) => {
		form.addEventListener( 'submit', ( event ) => {
			if ( ! window.confirm( form.dataset.confirm || '' ) ) {
				event.preventDefault();
			}
		} );
	} );

	root.querySelectorAll( '[data-webhook-form]' ).forEach( ( form ) => {
		const choices = Array.from( form.querySelectorAll( 'input[name="events[]"]' ) );
		const error = form.querySelector( '[data-webhook-error]' );

		const clearError = () => {
			if ( error && choices.some( ( choice ) => choice.checked ) ) {
				error.hidden = true;
			}
		};

		choices.forEach( ( choice ) => choice.addEventListener( 'change', clearError ) );
		form.addEventListener( 'submit', ( event ) => {
			if ( choices.some( ( choice ) => choice.checked ) ) {
				return;
			}
			event.preventDefault();
			if ( error ) {
				error.hidden = false;
			}
			if ( choices[ 0 ] ) {
				choices[ 0 ].focus();
			}
		} );
	} );

	root.querySelectorAll( 'form[data-loading-label]' ).forEach( ( form ) => {
		form.addEventListener( 'submit', ( event ) => {
			if ( event.defaultPrevented ) {
				return;
			}

			const state = form.dataset.loadingLabel;
			const loadingText = state === 'saving'
				? ( config.saving || 'Saving…' )
				: ( config.creating || 'Creating…' );
			const buttons = Array.from( form.querySelectorAll( 'button[type="submit"]' ) );

			if ( form.id ) {
				root.querySelectorAll( 'button[form="' + form.id + '"]' ).forEach( ( button ) => buttons.push( button ) );
			}

			buttons.forEach( ( button ) => {
				const label = button.querySelector( '[data-submit-label]' ) ||
					( button.matches( '[data-submit-label]' ) ? button : null );
				button.disabled = true;
				button.setAttribute( 'aria-busy', 'true' );
				if ( label ) {
					label.textContent = loadingText;
				}
			} );
		} );
	} );

	root.querySelectorAll( '[data-table-controls]' ).forEach( ( controls ) => {
		const table = document.getElementById( controls.dataset.tableControls );
		if ( ! table ) {
			return;
		}

		const search = controls.querySelector( '[data-table-search]' );
		const status = controls.querySelector( '[data-table-status]' );
		const count = controls.querySelector( '[data-table-count]' );
		const rows = Array.from( table.querySelectorAll( '[data-table-row]' ) );
		const empty = table.querySelector( '[data-table-empty]' );

		const applyFilters = () => {
			const query = search ? search.value.trim().toLocaleLowerCase() : '';
			const selectedStatus = status ? status.value : '';
			let visible = 0;

			rows.forEach( ( row ) => {
				const haystack = ( row.dataset.search || '' ).toLocaleLowerCase();
				const matchesQuery = ! query || haystack.includes( query );
				const matchesStatus = ! selectedStatus || row.dataset.status === selectedStatus;
				const show = matchesQuery && matchesStatus;
				row.hidden = ! show;
				if ( show ) {
					visible += 1;
				}
			} );

			if ( empty ) {
				empty.hidden = visible !== 0;
			}
			if ( count ) {
				const format = config.recordCount || '%d records';
				count.textContent = format.replace( '%d', String( visible ) );
			}
		};

		if ( search ) {
			search.addEventListener( 'input', applyFilters );
		}
		if ( status ) {
			status.addEventListener( 'change', applyFilters );
		}
	} );

	root.querySelectorAll( '[data-tool-manager]' ).forEach( ( manager ) => {
		const search = manager.querySelector( '[data-tool-search]' );
		const groupFilter = manager.querySelector( '[data-tool-group-filter]' );
		const count = manager.querySelector( '[data-tool-count]' );
		const empty = manager.querySelector( '[data-tool-empty]' );
		const enableAll = manager.querySelector( '[data-tool-enable-all]' );
		const disableAll = manager.querySelector( '[data-tool-disable-all]' );
		const groups = Array.from( manager.querySelectorAll( '[data-tool-group]' ) );
		const rows = Array.from( manager.querySelectorAll( '[data-tool-row]' ) );
		const operationPanels = Array.from( manager.querySelectorAll( '[data-operation-panel]' ) );

		const updateRowState = ( row ) => {
			const toggle = row.querySelector( '[data-tool-toggle]' );
			const status = row.querySelector( '[data-tool-status]' );
			const exposed = Boolean( toggle && toggle.checked );
			const state = exposed ? 'exposed' : 'disabled';

			row.dataset.toolState = state;
			row.classList.toggle( 'is-exposed', exposed );
			row.classList.toggle( 'is-disabled', ! exposed );
			if ( status ) {
				status.classList.toggle( 'fmp-tool-state--exposed', exposed );
				status.classList.toggle( 'fmp-tool-state--disabled', ! exposed );
				status.textContent = exposed
					? ( config.toolEnabled || 'Exposed' )
					: ( config.toolDisabled || 'Disabled' );
			}
		};

		const syncGroup = ( group ) => {
			const groupToggle = group.querySelector( '[data-tool-group-toggle]' );
			const toolToggles = Array.from( group.querySelectorAll( '[data-tool-toggle]' ) );
			if ( ! groupToggle || ! toolToggles.length ) {
				return;
			}

			const enabled = toolToggles.filter( ( toggle ) => toggle.checked ).length;
			groupToggle.checked = enabled === toolToggles.length;
			groupToggle.indeterminate = enabled > 0 && enabled < toolToggles.length;
		};

		const setTools = ( toolRows, exposed ) => {
			toolRows.forEach( ( row ) => {
				const toggle = row.querySelector( '[data-tool-toggle]' );
				if ( toggle ) {
					toggle.checked = exposed;
					updateRowState( row );
				}
			} );
			groups.forEach( syncGroup );
		};

		const updateOperationState = ( operationRow ) => {
			const toggle = operationRow.querySelector( '[data-operation-toggle]' );
			const status = operationRow.querySelector( '[data-operation-status]' );
			const enabled = Boolean( toggle && toggle.checked );

			operationRow.classList.toggle( 'is-enabled', enabled );
			operationRow.classList.toggle( 'is-disabled', ! enabled );
			if ( status ) {
				status.classList.toggle( 'fmp-operation-state--enabled', enabled );
				status.classList.toggle( 'fmp-operation-state--disabled', ! enabled );
				status.textContent = enabled
					? ( config.operationEnabled || 'Enabled' )
					: ( config.operationDisabled || 'Disabled' );
			}
		};

		const updateOperationSummary = ( panel ) => {
			const toggles = Array.from( panel.querySelectorAll( '[data-operation-toggle]' ) );
			const enabled = toggles.filter( ( toggle ) => toggle.checked ).length;
			const summary = panel.querySelector( '[data-operation-summary]' );
			if ( summary ) {
				const format = config.operationCount || '%1$d of %2$d operations enabled';
				summary.textContent = format
					.replace( '%1$d', String( enabled ) )
					.replace( '%2$d', String( toggles.length ) );
			}
		};

		const setOperations = ( panel, mode, enabled ) => {
			panel.querySelectorAll( '[data-operation-row]' ).forEach( ( operationRow ) => {
				if ( mode && operationRow.dataset.operationMode !== mode ) {
					return;
				}
				const toggle = operationRow.querySelector( '[data-operation-toggle]' );
				if ( toggle ) {
					toggle.checked = enabled;
					updateOperationState( operationRow );
				}
			} );
			updateOperationSummary( panel );
		};

		const applyFilters = () => {
			const query = search ? search.value.trim().toLocaleLowerCase() : '';
			const selectedGroup = groupFilter ? groupFilter.value : '';
			let visible = 0;

			groups.forEach( ( group ) => {
				const groupMatches = ! selectedGroup || group.dataset.groupKey === selectedGroup;
				let groupVisible = 0;

				group.querySelectorAll( '[data-tool-row]' ).forEach( ( row ) => {
					const haystack = ( row.dataset.search || '' ).toLocaleLowerCase();
					const show = groupMatches && ( ! query || haystack.includes( query ) );
					row.hidden = ! show;
					if ( show ) {
						groupVisible += 1;
						visible += 1;
					}
				} );

				group.hidden = groupVisible === 0;
			} );

			if ( empty ) {
				empty.hidden = visible !== 0;
			}
			if ( count ) {
				const format = config.toolsVisible || '%1$d of %2$d tools visible';
				count.textContent = format
					.replace( '%1$d', String( visible ) )
					.replace( '%2$d', String( rows.length ) );
			}
		};

		rows.forEach( ( row ) => {
			const toggle = row.querySelector( '[data-tool-toggle]' );
			updateRowState( row );
			if ( toggle ) {
				toggle.addEventListener( 'change', () => {
					updateRowState( row );
					const group = row.closest( '[data-tool-group]' );
					if ( group ) {
						syncGroup( group );
					}
				} );
			}
		} );

		operationPanels.forEach( ( panel ) => {
			const operationRows = Array.from( panel.querySelectorAll( '[data-operation-row]' ) );
			const toolRow = panel.closest( '[data-tool-row]' );
			const disclosure = toolRow ? toolRow.querySelector( '[data-operation-disclosure]' ) : null;
			const enableReads = panel.querySelector( '[data-operation-enable-reads]' );
			const disableWrites = panel.querySelector( '[data-operation-disable-writes]' );

			operationRows.forEach( ( operationRow ) => {
				const toggle = operationRow.querySelector( '[data-operation-toggle]' );
				updateOperationState( operationRow );
				if ( toggle ) {
					toggle.addEventListener( 'change', () => {
						updateOperationState( operationRow );
						updateOperationSummary( panel );
					} );
				}
			} );
			updateOperationSummary( panel );

			if ( disclosure ) {
				disclosure.addEventListener( 'click', () => {
					const expanded = disclosure.getAttribute( 'aria-expanded' ) === 'true';
					disclosure.setAttribute( 'aria-expanded', expanded ? 'false' : 'true' );
					panel.hidden = expanded;
				} );
			}
			if ( enableReads ) {
				enableReads.addEventListener( 'click', () => setOperations( panel, 'read', true ) );
			}
			if ( disableWrites ) {
				disableWrites.addEventListener( 'click', () => setOperations( panel, 'write', false ) );
			}
		} );

		groups.forEach( ( group ) => {
			const groupToggle = group.querySelector( '[data-tool-group-toggle]' );
			syncGroup( group );
			if ( groupToggle ) {
				groupToggle.addEventListener( 'change', () => {
					setTools( Array.from( group.querySelectorAll( '[data-tool-row]' ) ), groupToggle.checked );
				} );
			}
		} );

		if ( enableAll ) {
			enableAll.addEventListener( 'click', () => setTools( rows, true ) );
		}
		if ( disableAll ) {
			disableAll.addEventListener( 'click', () => setTools( rows, false ) );
		}
		if ( search ) {
			search.addEventListener( 'input', applyFilters );
		}
		if ( groupFilter ) {
			groupFilter.addEventListener( 'change', applyFilters );
		}

		applyFilters();
	} );
}() );
