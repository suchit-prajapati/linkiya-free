import './sidebar.css';
import { registerPlugin } from '@wordpress/plugins';
import { PluginSidebar, PluginSidebarMoreMenuItem } from '@wordpress/edit-post';
import { useSelect, useDispatch } from '@wordpress/data';
import { useState, useEffect } from '@wordpress/element';
import {
    Button, CheckboxControl, Spinner, Notice, PanelBody, PanelRow, TextControl,
} from '@wordpress/components';
import { __ } from '@wordpress/i18n';

const ICON = (
    <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" strokeWidth="2" strokeLinecap="round" strokeLinejoin="round">
        <path d="M10 13a5 5 0 0 0 7.54.54l3-3a5 5 0 0 0-7.07-7.07l-1.72 1.71" />
        <path d="M14 11a5 5 0 0 0-7.54-.54l-3 3a5 5 0 0 0 7.07 7.07l1.71-1.71" />
    </svg>
);

const STATUS = { IDLE:'idle', LOADING:'loading', DONE:'done', APPLYING:'applying', APPLIED:'applied', ERROR:'error' };

// Build absolute admin URL (works regardless of WP install path)
const adminUrl = ( page ) => {
    const base = linkiyaData.adminUrl || '/wp-admin/';
    return base + 'admin.php?page=' + page;
};

function SmartInternalLinkerSidebar() {
    const { postId, currentContent } = useSelect( ( select ) => ( {
        postId:         select( 'core/editor' ).getCurrentPostId(),
        currentContent: select( 'core/editor' ).getEditedPostAttribute( 'content' ),
    } ) );
    const { editPost } = useDispatch( 'core/editor' );

    const isPro = linkiyaData.isPro;

    const [status,        setStatus]        = useState( STATUS.IDLE );
    const [suggestions,   setSuggestions]   = useState( [] );
    const [checked,       setChecked]       = useState( {} );
    const [anchorTexts,   setAnchorTexts]   = useState( {} );
    const [editingAnchor, setEditingAnchor] = useState( {} );
    const [errorMsg,      setErrorMsg]      = useState( '' );
    const [appliedCount,  setAppliedCount]  = useState( 0 );
    const [orphanCount,   setOrphanCount]   = useState( null );

    // Orphan count teaser — only available in Pro
    // useEffect omitted in free version; Pro plugin overrides sidebar via linkiya_sidebar_data filter

    /* ── Run analysis ─────────────────────────────────────────────── */

    const runAnalysis = async () => {
        setStatus( STATUS.LOADING );
        setSuggestions( [] );
        setChecked( {} );
        setAnchorTexts( {} );
        setEditingAnchor( {} );
        setErrorMsg( '' );

        try {
            const res = await fetch( `${linkiyaData.restUrl}/suggest`, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': linkiyaData.nonce },
                body: JSON.stringify( { post_id: postId, content: currentContent } ),
            } );
            const data = await res.json();
            if ( ! res.ok ) throw new Error( data.message || data.error || __( 'Server error', 'linkiya' ) );

            if ( data.blacklisted ) {
                setErrorMsg( data.message || __( 'This post is blacklisted from linking.', 'linkiya' ) );
                setStatus( STATUS.ERROR );
                return;
            }

            const suggs = data.suggestions || [];
            setSuggestions( suggs );

            const defaultChecked = {};
            const defaultAnchors = {};
            suggs.forEach( s => {
                defaultChecked[ s.keyword ] = true;
                defaultAnchors[ s.keyword ] = s.keyword;
            } );
            setChecked( defaultChecked );
            setAnchorTexts( defaultAnchors );
            setStatus( STATUS.DONE );
        } catch ( err ) {
            setErrorMsg( err.message );
            setStatus( STATUS.ERROR );
        }
    };

    /* ── Apply links ──────────────────────────────────────────────── */

    const applyLinks = async () => {
        const accepted = suggestions
            .filter( s => checked[ s.keyword ] )
            .map( s => ( { ...s, anchor: anchorTexts[ s.keyword ] || s.keyword } ) );

        if ( ! accepted.length ) return;
        setStatus( STATUS.APPLYING );

        try {
            const res = await fetch( `${linkiyaData.restUrl}/apply`, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': linkiyaData.nonce },
                body: JSON.stringify( { post_id: postId, content: currentContent, accepted } ),
            } );
            const data = await res.json();
            if ( ! res.ok ) throw new Error( data.message || data.error || __( 'Apply failed', 'linkiya' ) );

            await editPost( { content: data.new_content } );
            setAppliedCount( data.applied );
            setStatus( STATUS.APPLIED );
        } catch ( err ) {
            setErrorMsg( err.message );
            setStatus( STATUS.ERROR );
        }
    };

    /* ── Helpers ──────────────────────────────────────────────────── */

    const toggleAll = val => {
        const next = {};
        suggestions.forEach( s => ( next[ s.keyword ] = val ) );
        setChecked( next );
    };

    const toggleAnchorEdit = keyword =>
        setEditingAnchor( prev => ( { ...prev, [keyword]: ! prev[keyword] } ) );

    const selectedCount = Object.values( checked ).filter( Boolean ).length;

    /* ── Render ───────────────────────────────────────────────────── */

    return (
        <>
            <PluginSidebarMoreMenuItem target="linkiya-sidebar">
                { __( 'Linkiya', 'linkiya' ) }
                { isPro && <span style={{color:'#10b981',fontSize:'10px',fontWeight:700,marginLeft:4}}>PRO</span> }
            </PluginSidebarMoreMenuItem>

            <PluginSidebar name="linkiya-sidebar" title={ __( 'Linkiya', 'linkiya' ) } icon={ ICON }>
                <PanelBody>

                    { /* Pro / Free banner */ }
                    { isPro ? (
                        <PanelRow>
                            <div className="linkiya-pro-badge">⚡ { __( 'Pro — All features active', 'linkiya' ) }</div>
                        </PanelRow>
                    ) : (
                        <PanelRow>
                            <div className="linkiya-free-banner">
                                { __( 'Free version — scanning Posts & Pages only.', 'linkiya' ) }
                                { ' ' }
                                <a href={ 'https://www.mypluginstore.com/linkiya' } target="_blank" rel="noreferrer">
                                    { __( 'Upgrade to Pro →', 'linkiya' ) }
                                </a>
                            </div>
                        </PanelRow>
                    ) }

                    <PanelRow>
                        <p className="linkiya-intro">
                            { __( 'Scan content for keywords found in published posts and apply internal links.', 'linkiya' ) }
                        </p>
                    </PanelRow>

                    { /* Orphan count teaser */ }
                    { orphanCount !== null && orphanCount > 0 && (
                        <PanelRow>
                            <div className="linkiya-orphan-teaser">
                                <span className="linkiya-orphan-count">⚠️ { orphanCount }</span>
                                { ' ' + __( 'orphaned posts found', 'linkiya' ) + ' ' }
                                <a
                                    href={ adminUrl( isPro ? 'linkiya-orphans' : '' ) || 'https://www.mypluginstore.com/linkiya' }
                                    className="linkiya-orphan-link"
                                    target="_blank"
                                    rel="noreferrer"
                                >
                                    { isPro
                                        ? __( 'Fix them →', 'linkiya' )
                                        : __( 'Upgrade to Pro →', 'linkiya' )
                                    }
                                </a>
                            </div>
                        </PanelRow>
                    ) }

                    { /* Run button */ }
                    { ( status === STATUS.IDLE || status === STATUS.ERROR || status === STATUS.APPLIED ) && (
                        <PanelRow>
                            <Button variant="primary" className="linkiya-run-btn" onClick={ runAnalysis } icon={ ICON }>
                                { __( 'Run Internal Linking', 'linkiya' ) }
                            </Button>
                        </PanelRow>
                    ) }

                    { status === STATUS.LOADING && (
                        <PanelRow>
                            <div className="linkiya-loading">
                                <Spinner />
                                <span>{ __( 'Analysing content…', 'linkiya' ) }</span>
                            </div>
                        </PanelRow>
                    ) }

                    { status === STATUS.ERROR && (
                        <PanelRow>
                            <Notice status="error" isDismissible={ false }>{ errorMsg }</Notice>
                        </PanelRow>
                    ) }

                    { status === STATUS.APPLIED && (
                        <PanelRow>
                            <Notice status="success" isDismissible={ false }>
                                { appliedCount }{ ' ' }
                                { __( 'internal link', 'linkiya' ) }{ appliedCount !== 1 ? 's' : '' }{ ' ' }
                                { __( 'applied! Hit', 'linkiya' ) }{ ' ' }
                                <strong>{ __( 'Update', 'linkiya' ) }</strong>{ ' ' }
                                { __( 'to save.', 'linkiya' ) }
                            </Notice>
                        </PanelRow>
                    ) }

                    { status === STATUS.DONE && (
                        <>
                            { suggestions.length === 0 ? (
                                <PanelRow>
                                    <Notice status="info" isDismissible={ false }>
                                        { __( 'No opportunities found in this post.', 'linkiya' ) }
                                    </Notice>
                                </PanelRow>
                            ) : (
                                <>
                                    <PanelRow>
                                        <div className="linkiya-summary">
                                            <span className="linkiya-badge">{ suggestions.length }</span>
                                            { ' ' + __( 'opportunities found', 'linkiya' ) }
                                        </div>
                                    </PanelRow>
                                    <PanelRow>
                                        <div className="linkiya-select-controls">
                                            <button className="linkiya-text-btn" onClick={ () => toggleAll( true ) }>
                                                { __( 'Select All', 'linkiya' ) }
                                            </button>
                                            <span className="linkiya-divider">|</span>
                                            <button className="linkiya-text-btn" onClick={ () => toggleAll( false ) }>
                                                { __( 'Select None', 'linkiya' ) }
                                            </button>
                                        </div>
                                    </PanelRow>

                                    <div className="linkiya-suggestions-list">
                                        { suggestions.map( s => (
                                            <div key={ s.keyword } className="linkiya-suggestion-row">
                                                <div className="linkiya-suggestion-main">
                                                    <CheckboxControl
                                                        checked={ !! checked[ s.keyword ] }
                                                        onChange={ val => setChecked( prev => ( { ...prev, [ s.keyword ]: val } ) ) }
                                                        label={
                                                            <span className="linkiya-suggestion-label">
                                                                <span className="linkiya-keyword">
                                                                    { anchorTexts[ s.keyword ] || s.keyword }
                                                                </span>
                                                                <span className="linkiya-arrow">→</span>
                                                                <span className="linkiya-post-title" title={ s.post_title }>
                                                                    { s.post_title.length > 32
                                                                        ? s.post_title.substring( 0, 32 ) + '…'
                                                                        : s.post_title }
                                                                </span>
                                                                { s.post_type && s.post_type !== 'post' && s.post_type !== 'page' && (
                                                                    <span className="linkiya-cpt-badge">{ s.post_type }</span>
                                                                ) }
                                                            </span>
                                                        }
                                                    />
                                                    { /* Anchor text edit toggle */ }
                                                    <button
                                                        className={ `linkiya-edit-anchor-btn${ editingAnchor[ s.keyword ] ? ' active' : '' }` }
                                                        onClick={ () => toggleAnchorEdit( s.keyword ) }
                                                        title={ __( 'Edit anchor text', 'linkiya' ) }
                                                        type="button"
                                                    >✏️</button>
                                                </div>

                                                { /* Anchor text edit field */ }
                                                { editingAnchor[ s.keyword ] && (
                                                    <div className="linkiya-anchor-edit">
                                                        <TextControl
                                                            label={ __( 'Anchor text', 'linkiya' ) }
                                                            value={ anchorTexts[ s.keyword ] || s.keyword }
                                                            onChange={ val => setAnchorTexts( prev => ( { ...prev, [s.keyword]: val } ) ) }
                                                            placeholder={ s.keyword }
                                                            help={ __( 'This text will be used as the link anchor.', 'linkiya' ) }
                                                        />
                                                    </div>
                                                ) }
                                            </div>
                                        ) ) }
                                    </div>

                                    <PanelRow>
                                        <div className="linkiya-apply-row">
                                            <Button
                                                variant="primary"
                                                className="linkiya-apply-btn"
                                                onClick={ applyLinks }
                                                disabled={ selectedCount === 0 || status === STATUS.APPLYING }
                                                isBusy={ status === STATUS.APPLYING }
                                            >
                                                { status === STATUS.APPLYING
                                                    ? __( 'Applying…', 'linkiya' )
                                                    : `${ __( 'Apply', 'linkiya' ) } ${ selectedCount } ${ __( 'Link', 'linkiya' ) }${ selectedCount !== 1 ? 's' : '' }` }
                                            </Button>
                                            <Button
                                                variant="secondary"
                                                className="linkiya-rerun-btn"
                                                onClick={ runAnalysis }
                                                disabled={ status === STATUS.APPLYING }
                                            >
                                                { __( 'Re-scan', 'linkiya' ) }
                                            </Button>
                                        </div>
                                    </PanelRow>
                                </>
                            ) }
                        </>
                    ) }

                </PanelBody>
            </PluginSidebar>
        </>
    );
}

registerPlugin( 'linkiya', { render: SmartInternalLinkerSidebar, icon: ICON } );