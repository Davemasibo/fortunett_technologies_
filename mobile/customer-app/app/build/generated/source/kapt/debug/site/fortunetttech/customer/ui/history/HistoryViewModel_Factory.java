package site.fortunetttech.customer.ui.history;

import dagger.internal.DaggerGenerated;
import dagger.internal.Factory;
import dagger.internal.QualifierMetadata;
import dagger.internal.ScopeMetadata;
import javax.annotation.processing.Generated;
import javax.inject.Provider;
import site.fortunetttech.customer.data.repository.ProfileRepository;

@ScopeMetadata
@QualifierMetadata
@DaggerGenerated
@Generated(
    value = "dagger.internal.codegen.ComponentProcessor",
    comments = "https://dagger.dev"
)
@SuppressWarnings({
    "unchecked",
    "rawtypes",
    "KotlinInternal",
    "KotlinInternalInJava",
    "cast"
})
public final class HistoryViewModel_Factory implements Factory<HistoryViewModel> {
  private final Provider<ProfileRepository> repoProvider;

  public HistoryViewModel_Factory(Provider<ProfileRepository> repoProvider) {
    this.repoProvider = repoProvider;
  }

  @Override
  public HistoryViewModel get() {
    return newInstance(repoProvider.get());
  }

  public static HistoryViewModel_Factory create(Provider<ProfileRepository> repoProvider) {
    return new HistoryViewModel_Factory(repoProvider);
  }

  public static HistoryViewModel newInstance(ProfileRepository repo) {
    return new HistoryViewModel(repo);
  }
}
