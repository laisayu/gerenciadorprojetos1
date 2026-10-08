<?php

namespace App\Filament\Resources\TaskResource\RelationManagers;

use Filament\Forms\Components\Textarea;
use Filament\Forms\Form;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Gate;

class CommentsRelationManager extends RelationManager
{
    protected static string $relationship = 'comments';

    protected static ?string $modelLabel = 'comentário';

    protected static ?string $pluralModelLabel = 'comentários';

    protected static ?string $title = 'Comentários';

    public function isReadOnly(): bool
    {
        return false;
    }

    protected function canCreate(): bool
    {
        return auth()->user()->can('comment', $this->getOwnerRecord());
    }

    public static function canViewForRecord(Model $ownerRecord, string $pageClass): bool
    {
        return auth()->user()->can('view', $ownerRecord);
    }

    public function form(Form $form): Form
    {
        return $form
            ->schema([
                Textarea::make('comment')
                    ->label('Comentário')
                    ->required(),
            ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query) => auth()->user()->can('view', $this->getOwnerRecord()) ? $query : $query->whereRaw('1 = 0'))
            ->recordTitleAttribute('comment')
            ->columns([
                TextColumn::make('user.name')
                    ->label('Usuário'),

                TextColumn::make('comment')
                    ->label('Comentário')
                    ->limit(50),

                TextColumn::make('created_at')
                    ->label('Data/hora')
                    ->dateTime('d/m/Y H:i'),
            ])
            ->headerActions([
                Tables\Actions\CreateAction::make()
                    ->mutateFormDataUsing(function (array $data): array {
                        Gate::authorize('comment', $this->getOwnerRecord());

                        return ['comment' => $data['comment'], 'user_id' => auth()->id()];
                    }),
            ])
            ->actions([
                Tables\Actions\EditAction::make(),
                Tables\Actions\DeleteAction::make(),
            ]);
    }
}
